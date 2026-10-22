=== User Session Manager ===
Contributors: aryanjasala
Tags: sessions, security, login, logout, devices
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See every device signed in to WordPress, sign out one of them, and limit sessions per role.

== Description ==

WordPress already keeps a record of every login: which browser, which IP address, when it started and when it ends. It just never shows it. The only control is "Log Out Everywhere Else" on your own profile, so nobody can see which devices are signed in, sign out just the lost phone, or see who is signed in across the site.

User Session Manager shows those sessions and lets you end them.

**For everyone, on their profile:**

* **Your sessions**: every browser signed in to your account, with the device ("Safari on iOS"), IP address, when it signed in, when it was last active and when it expires. The one you are using is marked "This device".
* **Sign out** any one of them, or all of them except this one.

**For administrators:**

* **Users > Sessions**: every active session on the site, with search by user or IP address, a role filter, sorting and paging. Sign out one session, or sign a user out everywhere, one at a time or in bulk.
* A **Sessions** column on the Users list, with each user's count linked to their sessions.
* **Session limit per role**: how many sessions a user may have at once. A login over the limit signs out the oldest session, or is refused with a clear message, your choice.
* **Idle timeout per role**: sessions left unused for a number of minutes are signed out.
* **Session length per role**, with and without "Remember Me".
* **New device email** (off by default): users get an email when they sign in from a browser and network not seen on their account in the last 30 days.
* **Shortened IP addresses**, if you would rather not store the full address.
* A **Site Health** check that warns when administrator sessions never time out.

"Last active" is saved inside each session, at most once every 5 minutes per session, so it costs almost nothing.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Privacy =

Sessions hold an IP address and a browser string. The plugin takes part in Tools > Export Personal Data (it lists a user's sessions) and Tools > Erase Personal Data (it signs the user out everywhere), adds suggested text to Settings > Privacy, and can store shortened IP addresses. The new device email keeps only a scrambled fingerprint of each browser and network, never the address, and forgets it after 30 days.

= WP-CLI =

* `wp user-session-manager list [--user=<user>]` lists active sessions with their handles.
* `wp user-session-manager revoke <handle>` signs out one session.
* `wp user-session-manager sign-out <user>` signs a user out everywhere.
* `wp user-session-manager prune` removes expired sessions and signs out idle ones.
* `wp user-session-manager policies` and `set-policy <role>` read and change the per-role policies.
* `wp user-session-manager settings` reads and changes the other settings.

= For developers =

Every limit and default has a filter, and every sign out fires an action. On WordPress 6.9 and later, AI agents can list sessions and sign them out through the Abilities API. The full list is in [the reference](https://github.com/aryanjasala/user-session-manager/blob/main/docs/REFERENCE.md).

== Frequently Asked Questions ==

= Does signing out a session log that browser out right away? =

On its next request. The session is deleted at once, so the next page it loads, or the next background check from an open admin tab, lands on the login screen.

= What counts as activity for the idle timeout? =

Any page or REST request made while signed in. The Heartbeat checks an open admin tab makes every minute do not count, so a forgotten tab still times out.

= What happens to people already signed in when I turn on a limit? =

Nothing until they sign in again. The limit is applied at login. An idle timeout applies to existing sessions from their next request.

= Will "Refuse the new login" lock out someone who lost their phone? =

It can, until the old session expires or an administrator signs it out from Users > Sessions. That is why signing out the oldest session is the default.

= Does it work with social login and two-factor plugins? =

Yes, for plugins that sign people in through WordPress's own login functions. A plugin that sets the login cookie itself cannot be refused before the session exists, so in refuse mode that session is ended right after it starts.

= Does it work on multisite? =

Yes. Users and their sessions are shared by the network, so the all-sessions screen and the settings live in Network Admin. Policies follow the roles a user has on the site they are using.

= My host or another plugin stores sessions in Redis. Will this work? =

No. The plugin reads sessions from user meta, where WordPress keeps them by default. When another plugin moves them, the screens and Site Health say so.

== Screenshots ==

1. Profile > Your sessions, with the current device marked.
2. Users > Sessions with every signed-in device on the site.
3. Settings > User Sessions with policies per role.
4. A login refused over the session limit.

== Changelog ==

= 1.0.0 =
* First release.
