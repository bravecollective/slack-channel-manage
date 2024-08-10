# slack-channel-manage

Adds and removes users from Slack channels based on Neucore groups.

The app first tries to find an active Slack account for the main character of a Neucore account, if that fails, 
it tries every alt and uses the first active Slack account found.

## Requirements

- A [Neucore](https://github.com/bravecollective/neucore) installation.
- Access to the database of the [neucore-plugin-slack](https://github.com/bravecollective/neucore-plugin-slack) plugin.
- PHP 8.1+ with curl, json and PDO extensions.

## Setup

- Create a Neucore app.
  - Add all relevant groups to it.
  - Add roles `app-groups`, `app-chars`.
- Create a Slack application at https://api.slack.com/apps.
  - Add desired Bot Token Scopes:
    - `groups:read` and `groups:write` for private channels,
    - `channels:read` and `channels:manage` for public channels.
  - Install to Workspace.
- Add bot to channel(s) that it should manage: `/invite @bot-name` (remove with `/kick @bot-name`).
- Copy `config.dist.php` to `config.php` and add your configuration.
- Add environment variables:
  - `SLACK_CHANNEL_MANAGE_NEUCORE_URL` Neucore API base URL, e.g. https://neucore.herokuapp.com
  - `SLACK_CHANNEL_MANAGE_NEUCORE_TOKEN` Base64 encoded id:secret string from the Neucore app.
  - `SLACK_CHANNEL_MANAGE_SLACK_TOKEN` Slack Bot User OAuth Token.
  - `SLACK_CHANNEL_MANAGE_SLACK_DB_DSN` Connection string for the "neucore-plugin-slack" plugin database, 
    e.g. "mysql:dbname=slack_signup;host=127.0.0.1;user=root;password=pass".
  - `SLACK_CHANNEL_MANAGE_CONFIG_FILE` Full path to the config file with channel and group mapping.

## Usage

Add and remove users:
```
bin/console run
```

When this app removes the last user from a channel, it is automatically renamed and archived by Slackbot.
If this app now tries to add a user to an archived channel, the error "is_archived" is issued.

Un-archive a channel:
```
bin/console unarchive AB12C3D45
```

Cron job example:
```
*/20 * * * * user . /path/to/.env.sh && /path/to/bin/console run >> /path/to/results-`date +\%Y-\%m`.log
```
