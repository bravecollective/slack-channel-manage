<?php

declare(strict_types=1);

namespace Brave\Slack;

class ConfigMap {

    public string $channelId;

    /**
     * @var int[]
     */
    public array $groupIds;

    /**
     * @param int[] $groupIds
     */
    public function __construct(string $channelId, array $groupIds)
    {
        $this->channelId = $channelId;
        $this->groupIds = $groupIds;
    }
}
