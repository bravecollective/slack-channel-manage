<?php

declare(strict_types=1);

namespace Brave\Slack;

class Config {

    public const ACTION_INVITE = 'invite';

    public const ACTION_KICK = 'kick';

    public string $channelId;

    /**
     * @var int[]
     */
    public array $groupIds;

    /**
     * @var string[]
     */
    public array $actions;

    public int $corporation;

    /**
     * @param int[] $groupIds
     */
    public function __construct(string $channelId, array $groupIds, array $actions, int $corporation)
    {
        $this->channelId = $channelId;
        $this->groupIds = $groupIds;
        $this->actions = $actions;
        $this->corporation = $corporation;
    }
}
