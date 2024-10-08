<?php

declare(strict_types=1);

namespace Brave\Slack;

use PDO;
use PDOException;

class ManageChannel
{
    private const SLACK_ACCOUNT_STATUS_ACTIVE = 'Active';

    private string $neucoreUrl;

    private string $neucoreToken;

    private string $slackToken;

    private PDO $pdo;

    /**
     * @var Config[]
     */
    private array $configMap;

    private array $ignoreUserKick = [];

    private string $userId = '';

    private int $slackRequestCount = 0;

    private array $groupCache;

    /**
     * Un-archive a channel - in case the last member was removed
     */
    public function unArchive(string $channel): bool
    {
        if (!$this->readConfig()) {
            return false;
        }

        $result = $this->slackApiRequest('POST', 'conversations.unarchive', 20, ['channel' => $channel]);
        if ($result && $result->ok) {
            echo 'Success.' . PHP_EOL;
        } else {
            $error = $result ? $result->error : 'unknown error';
            echo "Error: $error." . PHP_EOL;
        }

        return true;
    }

    public function run(): bool
    {
        $this->out('Start sync.');

        if (!$this->readConfig()) {
            return false;
        }

        // get user id of app
        // https://api.slack.com/methods/auth.test
        $identity = $this->slackApiRequest('GET', 'auth.test', 100);
        if ($identity && $identity->ok) {
            $this->userId = $identity->user_id;
        } else {
            $this->error('Error getting identity', $identity);
            return false;
        }

        // add members
        foreach ($this->configMap as $entry) {
            $this->processGroup($entry);
        }

        $this->out('Finished.');
        return true;
    }

    private function readConfig(): bool
    {
        $this->neucoreUrl = rtrim((string) getenv('SLACK_CHANNEL_MANAGE_NEUCORE_URL'), '/');
        $this->neucoreToken = (string) getenv('SLACK_CHANNEL_MANAGE_NEUCORE_TOKEN');
        $this->slackToken = (string) getenv('SLACK_CHANNEL_MANAGE_SLACK_TOKEN');
        $dbDns = (string) getenv('SLACK_CHANNEL_MANAGE_SLACK_DB_DSN');
        $configFile = (string) getenv('SLACK_CHANNEL_MANAGE_CONFIG_FILE');

        if (
            empty($this->neucoreUrl) ||
            empty($this->neucoreToken) ||
            empty($this->slackToken) ||
            empty($dbDns) ||
            empty($configFile)
        ) {
            $this->out('Missing at least one of the required environment variables.');
            return false;
        }

        try {
            $this->pdo = new PDO($dbDns, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $this->out($e->getMessage());
            return false;
        }

        if (!is_file($configFile) || !is_readable($configFile)) {
            $this->out("Cannot read config file $configFile.");
            return false;
        }

        $config = include $configFile;

        foreach ($config as $configKey => $configuration) {
            if ($configKey === 'ignoreUserKick') {
                $this->ignoreUserKick = $configuration;
                continue;
            }

            $channelId = (string) $configKey;
            if (empty($channelId)) {
                $this->out('Configuration error: Channel ID cannot be empty.');
                return false;
            }

            if (empty($configuration['groups']) || !is_array($configuration['groups'])) {
                $this->out('Configuration error: Neucore group IDs must be an array that is not empty.');
                return false;
            }
            foreach ($configuration['groups'] as $groupId) {
                if (!is_int($groupId) || $groupId < 1) {
                    $this->out('Configuration error: Neucore group ID must be an integer greater 0.');
                    return false;
                }
            }

            if (!isset($configuration['actions'])) {
                $this->out('Configuration error: Missing actions.');
            }
            $actions = explode(',', (string)$configuration['actions']);
            if ($actions[0] !== Config::ACTION_INVITE && $actions[0] !== Config::ACTION_KICK) {
                $this->out('Configuration error: Invalid actions.');
            }
            if (isset($actions[1]) && $actions[1] !== Config::ACTION_INVITE && $actions[1] !== Config::ACTION_KICK) {
                $this->out('Configuration error: Invalid actions.');
            }

            if (isset($configuration['corporation'])) {
                if (!is_array($configuration['corporation'])) {
                    $this->out('Configuration error: "corporation" must be an array.');
                    return false;
                }
                foreach ($configuration['corporation'] as $groupId => $corporationId) {
                    if (!is_int($groupId) || !is_int($corporationId)) {
                        $this->out('Configuration error: "corporation" keys and values must be an integers.');
                    }
                }
            }

            $this->configMap[] = new Config(
                $channelId,
                $configuration['groups'],
                $actions,
                $configuration['corporation'] ?? []
            );
        }

        return true;
    }

    private function processGroup(Config $config): void
    {
        $this->out("Processing Slack channel $config->channelId ...");

        $channelMembers = $this->getSlackChannelMembers($config->channelId);
        if (!is_array($channelMembers)) {
            return;
        }

        // get group members - main character from the accounts
        $groupMembers = $this->getCoreGroupMembers($config->groupIds, $config->corporation);
        if (!is_array($groupMembers)) {
            $this->out('Failed to get group members.');
            return;
        }

        // find Slack users of characters and vise versa
        $memberMap = $this->mapMembers($channelMembers, $groupMembers);
        if (!is_array($memberMap)) {
            $this->out('Failed to map members.');
            return;
        }
        $memberMap = $this->findSlackUsersForAlts($memberMap, $groupMembers);

        // add members
        if (in_array(Config::ACTION_INVITE, $config->actions)) {
            $this->addMember($groupMembers, $channelMembers, $memberMap, $config->channelId);
        }

        // remove members
        if (in_array(Config::ACTION_KICK, $config->actions)) {
            $this->removeMember($groupMembers, $channelMembers, $memberMap, $config->channelId);
        }
    }

    private function getSlackChannelMembers(string $channelId): ?array
    {
        $members = [];

        $nextCursor = '';
        do {
            // https://api.slack.com/methods/conversations.members
            $params = "channel=$channelId&cursor=$nextCursor&limit=100";
            $result = $this->slackApiRequest('GET', "conversations.members?$params", 100);
            if ($result && $result->ok) {
                $nextCursor = (string)$result->response_metadata->next_cursor;
                $members = array_merge($members, $result->members);
            } else {
                $this->error("Failed to get channel members for $channelId", $result);
                return null;
            }
        } while ($nextCursor !== '');

        return $members;
    }

    /**
     * @param int[] $groupIds
     * @param array<int, int> $corporation
     */
    private function getCoreGroupMembers(array $groupIds, array $corporation): ?array
    {
        $members = [];

        foreach ($groupIds as $groupId) {
            $cacheKey = "$groupId";
            if (isset($corporation[$groupId])) {
                $cacheKey = "$groupId-$corporation[$groupId]";
            }
            if (!isset($this->groupCache[$cacheKey])) {
                $param = isset($corporation[$groupId]) ? "corporation=$corporation[$groupId]" : '';
                $result = $this->httpRequest(
                    "$this->neucoreUrl/api/app/v1/group-members/$groupId?$param",
                    'GET',
                    ['Authorization: Bearer ' . $this->neucoreToken],
                );
                $groupMembers = json_decode((string) $result);
                if (!is_array($groupMembers)) {
                    return null;
                }
                $this->groupCache[$cacheKey] = $groupMembers;
            }

            $members = array_merge($members, $this->groupCache[$cacheKey]);
        }

        return array_keys(array_flip($members));
    }

    /**
     * @param string[] $slackUserIds
     * @param int[] $eveCharacterIds
     */
    private function mapMembers(array $slackUserIds, array $eveCharacterIds): ?array
    {
        if (empty($slackUserIds) && empty($eveCharacterIds)) {
            return [];
        }

        $paramSlack = implode(',', array_fill(0, count($slackUserIds), '?'));
        $paramEve = implode(',', array_fill(0, count($eveCharacterIds), '?'));
        if (empty($paramEve)) {
            $where = "slack_id IN ($paramSlack)";
        } elseif (empty($paramSlack)) {
            $where = "character_id IN ($paramEve)";
        } else {
            $where = "slack_id IN ($paramSlack) OR character_id IN ($paramEve)";
        }
        $stmt = $this->pdo->prepare(
            "SELECT character_id, slack_id FROM invite WHERE $where and account_status = ? ORDER BY character_id"
            // Define order so that if a player has multiple Slack accounts connected to alts but none connected
            // to the main character, it will always find the same alt.
        );
        try {
            $stmt->execute(array_merge($slackUserIds, $eveCharacterIds, [self::SLACK_ACCOUNT_STATUS_ACTIVE]));
        } catch (PDOException $e) {
            $this->out($e->getMessage());
            return null;
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['slack_id']] = (int) $row['character_id'];
        }

        return $map;
    }

    /**
     * Find Slack users for alts if main does not have a Slack accounts
     */
    private function findSlackUsersForAlts(array $memberMap, array $groupMembers): array
    {
        $missing = array_diff($groupMembers, $memberMap);
        if (empty($missing)) {
            return $memberMap;
        }

        $coreResult = $this->httpRequest(
            "$this->neucoreUrl/api/app/v1/characters",
            'POST',
            ['Authorization: Bearer ' . $this->neucoreToken, 'Content-Type: application/json; charset=utf-8'],
            array_values($missing)
        );
        $characters = json_decode((string)$coreResult);
        if (!is_array($characters)) {
            // an error here does not really matter, continue.
            return $memberMap;
        }

        $foundAccounts = 0;
        foreach ($characters as $charactersOfOnePlayer) {
            $altMap = $this->mapMembers([], $charactersOfOnePlayer);
            if (!is_array($altMap)) {
                // database error, continue
                continue;
            }
            foreach ($altMap as $slackUserId => $eveCharacterId) {
                // Use the main character ID for the mapping here, not the alt that is actually
                // mapped to the Slack user.
                $main = array_intersect($groupMembers, $charactersOfOnePlayer);
                if (count($main) === 1) { // should always be 1
                    // if the user is already a member of the channel: replace the existing mapping (alt with main)
                    // if the user is not a member of the channel: add new mapping
                    $memberMap[$slackUserId] = current($main); // index is not always 0!
                    $foundAccounts ++;
                    break; // one Slack account per player is enough
                }
            }
        }

        $this->out("Found $foundAccounts Slack accounts from alts.");

        return $memberMap;
    }

    private function addMember(
        array $groupMembers,
        array $channelMembers,
        array $memberMap,
        string $slackChannelId
    ): void {
        $memberMapFlipped = array_flip($memberMap);

        $membersToAdd = [];
        foreach ($groupMembers as $eveCharacterId) {
            $slackUserId = $memberMapFlipped[$eveCharacterId] ?? null;
            if ($slackUserId !== null && !in_array($slackUserId, $channelMembers)) {
                $membersToAdd[] = $slackUserId;
            }
        }
        foreach (array_chunk($membersToAdd, 200) as $membersToAddChunk) {
            $slackUserIds = implode(',', $membersToAddChunk);
            $data = ['channel' => $slackChannelId,  'users' => $slackUserIds];
            // https://api.slack.com/methods/conversations.invite
            $result = $this->slackApiRequest('POST', 'conversations.invite', 50, $data);
            if ($result && $result->ok) {
                $this->out("Added user(s) $slackUserIds to channel $slackChannelId");
            } else {
                $this->error("Failed to added user(s) $slackUserIds to channel $slackChannelId", $result);
            }
        }
    }

    private function removeMember(
        array $groupMembers,
        array $channelMembers,
        array $memberMap,
        string $channelId
    ): void {
        $usersToRemove = [];
        foreach ($channelMembers as $slackUserId) {
            $eveCharacterId = $memberMap[$slackUserId] ?? null;
            if ($eveCharacterId === null || !in_array($eveCharacterId, $groupMembers)) {
                $usersToRemove[] = $slackUserId;
            }
        }
        foreach ($usersToRemove as $slackUserId) {
            if ($slackUserId === $this->userId || in_array($slackUserId, $this->ignoreUserKick)) {
                // Do not try to remove the bot itself (prevent "cant_kick_self" error) or
                // any user/bot from the $ignoreUsers configuration.
                continue;
            }
            $data = ['channel' => $channelId,  'user' => $slackUserId];
            // https://api.slack.com/methods/conversations.kick
            $result = $this->slackApiRequest('POST', 'conversations.kick', 50, $data);
            if ($result && $result->ok) {
                $this->out("Removed user $slackUserId from channel $channelId");
            } else {
                $this->error("Failed to removed user $slackUserId from channel $channelId", $result);
            }
        }
    }

    private function slackApiRequest(string $method, string $path, int $rateLimit, ?array $data = null): ?object
    {
        if ($this->slackRequestCount > 0) {
            $sleepInSeconds = ceil(60/$rateLimit*10)/10;
            usleep((int) ($sleepInSeconds * 1000 * 1000));
        }
        $this->slackRequestCount ++;

        $headers = ['Authorization: Bearer ' . $this->slackToken];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $result = $this->httpRequest("https://slack.com/api/$path", $method, $headers, $data);

        $object = json_decode((string) $result);
        if ($object !== false) {
            return $object;
        }

        return null;
    }

    /**
     * @param string $method GET or POST
     */
    private function httpRequest(string $url, string $method, array $headers, ?array $content = null): ?string
    {
        $headers[] = 'User-Agent: BRAVE Slack Channel Manage (https://github.com/bravecollective/slack-channel-manage)';

        $handle = curl_init();

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, 1);
        }
        if ($content !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($content));
        }
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, false);

        $result = curl_exec($handle);

        $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errMsg = "Request $url failed:";
        if ($result === false) {
            $this->out("$errMsg Error ". curl_errno($handle) . ' ' . curl_error($handle));
            $result = null;
        } elseif ($code < 200 || $code > 299) {
            $this->out("$errMsg Error $code");
            $result = null;
        }

        curl_close($handle);

        return $result;
    }

    private function error(string $message, ?object $result): void
    {
        $error = $result ? $result->error : 'unknown error';
        $this->out("$message: $error.");
    }

    private function out(string $message): void
    {
        echo gmdate('Y-m-d H:i:s ') . $message . PHP_EOL;
    }
}
