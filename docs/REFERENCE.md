# Reference

## How it works

WordPress already keeps a record of every login in the `session_tokens` user meta: one entry per browser, with when it expires, the IP address, the browser string and the login time. The only control core gives you is "Log Out Everywhere Else" on your own profile.

User Session Manager reads and writes that same meta with the same structure. Core stores each session under a verifier, the SHA-256 hash of the token in the login cookie, and only ends a session when it has the raw token. This plugin ends a session from its verifier alone, so an admin can sign out a browser they never had the cookie for.

Verifiers never reach a page. The screens, WP-CLI and abilities use a **handle** instead: `<user ID>-<24 hex characters>`, an HMAC of the user ID and the verifier keyed with the site's auth salt. A handle cannot be turned back into the verifier or the token.

## Last active

Each session gets a `last_seen` time inside its own entry in `session_tokens`. It is set at login and saved again on an authenticated request (admin, REST, or the front end while logged in) when the last save is older than 5 minutes (`user_session_manager_last_seen_interval`). So a busy session writes to the database at most once every 5 minutes.

Heartbeat polls and cron do not count as activity, so a forgotten admin tab still goes idle (`user_session_manager_track_request`).

Sessions from before the plugin was active have no `last_seen` yet. They count as active until their next request records one.

## Policies

Set per role under **Settings > User Sessions** (**Network Admin > Settings > User Sessions** on multisite) or with `wp user-session-manager set-policy`. A user with several roles gets the most generous value of each: no limit beats any limit, and a longer limit beats a shorter one. Users with no role on the site have no limits.

| Setting | 0 means | Effect |
| --- | --- | --- |
| Sessions at once | no limit | On a login over the limit, the oldest session (by login time) is signed out, or the login is refused, depending on "Over the limit" |
| Sign out after idle (minutes) | never | A session not used for longer is refused on its next request and signed out. The hourly clean-up also removes it |
| Session length (hours) | 2 days | How long a session lasts without "Remember Me", through `auth_cookie_expiration` |
| With "Remember Me" (hours) | 14 days | How long a session lasts with "Remember Me" |

Idle sessions never count towards the limit. They are signed out first.

**Refuse the new login** works on the login form (`wp_signon()`), so other plugins' login forms that use it are covered too. A login that skips it and calls `wp_set_auth_cookie()` directly, such as some social login plugins, cannot be refused before the session exists. In refuse mode that new session is ended right away instead, and `user_session_manager_login_blocked` fires.

Password checks that create no session, such as XML-RPC and application passwords, are never refused.

## New device email

Off by default. When it is on, a user gets an email when they sign in from a browser and IP address pair not seen on their account in the last 30 days (the setting next to it). Browser here means the browser and system, such as "Safari on iOS", without the version, so an update is not a new device.

Sessions are deleted when they end, so the plugin keeps a short memory per user in the `user_session_manager_devices` user meta: an HMAC of each browser, system and IP address, with the browser label and when it was last seen. The IP address itself is not kept. At most 50 entries per user.

The first login the plugin sees for a user only fills that memory (from their current sessions too), so turning the email on does not email everyone.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `user_session_manager_capability` | filter | `( string $capability )`. Who may see everyone's sessions and sign other people out. Default `edit_users`; `list_users` is needed as well, and `edit_user` for the person being signed out |
| `user_session_manager_settings_capability` | filter | `( string $capability )`. Who may change the policies. Default `manage_options`, or `manage_network_options` on multisite |
| `user_session_manager_max_sessions` | filter | `( int $max, WP_User $user )`. Sessions a user may have at once. 0 means no limit |
| `user_session_manager_limit_mode` | filter | `( string $mode, WP_User $user )`. `evict_oldest` or `block`. Anything else falls back to `evict_oldest` |
| `user_session_manager_idle_timeout` | filter | `( int $minutes, WP_User $user )`. 0 turns the idle timeout off |
| `user_session_manager_session_lifetime` | filter | `( int $seconds, WP_User $user, bool $remember )`. Length of a new session. 0 or less keeps the WordPress default |
| `user_session_manager_last_seen_interval` | filter | `( int $seconds )`. How often "last active" is saved per session, at most. Default 300, 0 to 86400 |
| `user_session_manager_track_request` | filter | `( bool $track, int $user_id )`. Whether a request counts as activity. Default true, except Heartbeat and cron |
| `user_session_manager_parse_user_agent` | filter | `( array $parsed, string $ua )`. `browser`, `version`, `os`, `device` (desktop, mobile, tablet, bot or unknown) and `label`. Plug in a parser library here |
| `user_session_manager_mask_ip` | filter | `( bool $mask, string $ip, int $user_id )`. Whether to store a shortened IP address (the last part of IPv4 set to zero, the last 80 bits of IPv6) |
| `user_session_manager_new_device_email` | filter | `( array\|false $email, WP_User $user, array $session )`. `to`, `subject`, `message` and `headers`. Return false or an empty `to` to skip it |
| `user_session_manager_device_memory_days` | filter | `( int $days )`. Days a browser and IP address stay known. From the setting, 1 to 365 |
| `user_session_manager_device_limit` | filter | `( int $max )`. Browser and IP address pairs remembered per user. Default 50 |
| `user_session_manager_blocked_login_message` | filter | `( string $message, WP_User $user, int $max )`. The login form error in refuse mode. Basic HTML allowed |
| `user_session_manager_list_per_page` | filter | `( int $per_page )`. Rows per page on Users > Sessions. Default 20 |
| `user_session_manager_max_users_scanned` | filter | `( int $max )`. Signed-in users one listing reads at most. Default 10000 |
| `user_session_manager_prune_batch` | filter | `( int $size )`. Users one run of the hourly clean-up looks at. Default 500 |
| `user_session_manager_session_started` | action | `( WP_User $user, array $session )`. A user signed in and their new session was saved, after the limit was applied |
| `user_session_manager_session_revoked` | action | `( int $user_id, array $session, string $context )`. One session was signed out. `$context` is `profile`, `admin`, `bulk`, `cli` or `ability` |
| `user_session_manager_user_signed_out_everywhere` | action | `( int $user_id, int $count, string $context )`. Every session of a user was ended. `$context` also can be `privacy` |
| `user_session_manager_session_evicted` | action | `( int $user_id, array $evicted, array $new )`. An old session was signed out to make room for a new login |
| `user_session_manager_login_blocked` | action | `( WP_User $user, int $max, int $active )`. A login was refused over the limit |
| `user_session_manager_idle_session_ended` | action | `( int $user_id, array $session, string $context )`. An idle session was signed out. `$context` is `request`, `login` or `prune` |
| `user_session_manager_new_device_login` | action | `( WP_User $user, array $session )`. A login from a browser and IP address pair not seen recently |
| `user_session_manager_before_bulk` | action | `( string $action, string[] $handles )`. Before a bulk sign out on Users > Sessions. `$action` is `revoke` or `sign_out` |
| `user_session_manager_after_bulk` | action | `( string $action, string[] $handles, int $count )`. After a bulk sign out |
| `user_session_manager_pruned` | action | `( array $result )`. After a batch of the clean-up: `users`, `expired`, `idle`, `last` and `done` |
| `user_session_manager_settings_saved` | action | `( array $settings, array $old )`. The policies or settings changed, from the screen or WP-CLI |

Every `$session` array passed to an action has `expiration`, `ip`, `ua`, `login`, `last_seen` and `handle`. Never the token or the verifier.

### Examples

```php
// Administrators: one session at a time, refused rather than evicted, signed out after 20 idle minutes.
add_filter( 'user_session_manager_max_sessions', function ( $max, $user ) {
	return in_array( 'administrator', $user->roles, true ) ? 1 : $max;
}, 10, 2 );
add_filter( 'user_session_manager_limit_mode', function ( $mode, $user ) {
	return in_array( 'administrator', $user->roles, true ) ? 'block' : $mode;
}, 10, 2 );
add_filter( 'user_session_manager_idle_timeout', function ( $minutes, $user ) {
	return in_array( 'administrator', $user->roles, true ) ? 20 : $minutes;
}, 10, 2 );

// Send new device alerts for administrators to the security team as well.
add_filter( 'user_session_manager_new_device_email', function ( $email, $user ) {
	if ( in_array( 'administrator', $user->roles, true ) ) {
		$email['headers'][] = 'Bcc: security@example.com';
	}
	return $email;
}, 10, 2 );

// Log every sign out to your own audit trail.
add_action( 'user_session_manager_session_revoked', function ( $user_id, $session, $context ) {
	error_log( sprintf( 'Session of user %d on %s signed out from %s', $user_id, $session['ip'], $context ) );
}, 10, 3 );
```

## Abilities

On WordPress 6.9 and later, User Session Manager registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. Useful for "I lost my phone" or a suspected break-in.

| Ability | Does | Needs |
| --- | --- | --- |
| `user-session-manager/list-sessions` | Read-only. Sessions with handle, device, IP address, signed in, last active and expires. No input: your own. `user` (ID, login or email): that user's. `all`: everyone's. `limit`, 1 to 100, default 50 | Any logged-in user for their own. The plugin capability, `list_users` and `edit_user` for someone else's |
| `user-session-manager/revoke-session` | Signs out the session with `handle`. Destructive and idempotent: a session that already ended returns `revoked: false` | Your own session, or the plugin capability for someone else's |
| `user-session-manager/sign-out-user` | Ends every session of `user`. Returns `sessions_ended` | Yourself, or the plugin capability for someone else |

Run them over REST with `GET .../list-sessions/run?input[user]=alice` and `DELETE .../revoke-session/run?input[handle]=7-...`.

## WP-CLI

```sh
wp user-session-manager list [--user=<user>] [--fields=<fields>] [--format=table|csv|json|yaml|ids|count]
wp user-session-manager revoke <handle>...
wp user-session-manager sign-out <user>                 # ID, login or email
wp user-session-manager prune                           # expired and idle sessions, every user
wp user-session-manager policies [--format=<format>]
wp user-session-manager set-policy <role> [--max-sessions=<n>] [--idle-minutes=<n>] [--lifetime-hours=<n>] [--remember-hours=<n>]
wp user-session-manager settings [--limit-mode=evict_oldest|block] [--new-device-email=on|off] [--device-days=<n>] [--mask-ip=on|off] [--format=json]
```

`--user` on `list` is WP-CLI's global flag, so the command also runs as that user. Extra fields for `--fields`: `user_id`, `device_type` and `ua`.

## Data

| Where | Contents |
| --- | --- |
| `session_tokens` user meta (core's) | The sessions themselves. The plugin adds `last_seen` to each, and shortens `ip` when asked |
| `user_session_manager_devices` user meta | Only with the new device email on: HMAC fingerprints of browser, system and IP address, with the browser label and last seen time. Entries older than 30 days are dropped |
| `user_session_manager_settings` option (network option on multisite) | Per-role policies, over-the-limit mode, new device email, days to remember devices, IP shortening |
| `user_session_manager_prune_cursor` option (network option on multisite) | Where the hourly clean-up carries on |

The `user_session_manager_prune` cron event runs hourly, on the main site of a network. Each run looks at 500 users with sessions, removes expired ones (core only does that when the user signs in again) and signs out idle ones.

The total shown in Site Health is cached in the object cache until any user's sessions change.

Uninstalling removes the settings, the cron event and the device memory on every site. The `last_seen` value inside core's sessions stays: core ignores it, and it goes away with each session.

## Privacy

Sessions hold an IP address and a browser string, which are personal data. So:

- **Tools > Export Personal Data** lists a user's sessions (device, browser string, IP address, signed in, last active, expires) and remembered devices
- **Tools > Erase Personal Data** signs the user out everywhere and forgets their devices
- **Settings > Privacy** gets suggested text for your privacy policy
- "Store shortened IP addresses" keeps only the first three parts of an IPv4 address (the first 48 bits of IPv6), on new sessions at login and on older ones the next time they are used
- Sessions are deleted when they end, so how long they are kept follows the session length per role. Device memory is kept for 30 days by default

## Multisite

Users and their sessions are shared by the whole network. So on multisite:

- the all-sessions screen is **Network Admin > Users > Sessions**, and the settings are **Network Admin > Settings > User Sessions**, stored as network options
- a policy applies through the roles the user has on the site they are using. Users with no role there have no limits on that site
- a site admin cannot sign out a super admin: signing someone out also needs `edit_user` for them

## Limits

- Sessions must live in user meta, the WordPress default. A plugin that moves them elsewhere through the `session_token_manager` filter (Redis, a custom table) leaves nothing to read. The screens say so, and Site Health flags it.
- Users > Sessions reads every signed-in user's sessions, 500 users at a time, then sorts and pages them. On a site with more than 10,000 signed-in users it stops there and says so; filter by role or search to narrow it down.
- The IP address is the one PHP sees (`REMOTE_ADDR`), as core records it. Behind a proxy or CDN that does not pass the visitor's address through, it is the proxy's.
