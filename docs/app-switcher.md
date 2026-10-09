# The application switcher (2026-10-09)

K31 (the kernel's `docs/build-specs/kernel-app-switcher.md`, approved D1–D10; ProcessCore the exemplar; the integration plugin's
`sign-on-and-directory.md` §8): the header carries a **Helpdesk** button and a dropdown of every application the person may open,
so a person moves between applications without the launcher page. `app/switcher.php` (`os_my_applications()` — the kernel's
`GET /api/v1/apps/mine.php` as this person, cached five minutes in the session; `os_launch_href()` on `OS_LAUNCHER_URL`),
`app/views/shared/app-switcher.php` in `header-right` where the ad-hoc "All applications" icon was (the button when the person
holds the Help Desk and this is not it; one row per application or per scope, the current one marked and not linked; All
applications and, for a super-admin, Operating system at the foot; every link a full navigation), the APPLICATION SWITCHER
section of `app-overrides.css`. Proven by `scripts/prove-switcher.sh` from a checkout on a scratch database: the code and the
layout, then in process against the real kernel with this install's token (a read).
