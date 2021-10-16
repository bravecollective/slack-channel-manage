<?php
return [
    // Slack user IDs that should never be kicked from a channel, e.g. for bots.
    'ignoreUserKick' => ['U021LBK7H6Y'], // slack-invite app

    'AB12C3D45' => [ // Slack channel ID (string)
        'groups' => [70, 80], // Integer array with Neucore group IDs (user needs one of them)
        'actions' => 'invite,kick', // Can be 'invite', 'kick' or both (comma separated).
        'corporation' => [70 => 98169165], // Filters Neucore member list (contains only mains) by corporation
    ],
];
