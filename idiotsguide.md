# idiotsguide.md

## What this is

Conflict Detective is a tool for when WordPress is “fine” right up until it is not.
It helps you figure out which plugin is causing errors or conflicts.

If your site is broken, you are not here for philosophy.
You want a way in, and you want a name.

## Words you are about to see

### Plugin conflict
Two plugins both try to do something, and they do not agree on how reality works.

### Safe mode
A mode where almost nothing loads, so you can get into wp-admin and stop the bleeding.

### Scan
A structured set of tests. It checks plugins in a systematic way.

## The basic recovery flow

1. Open your site in a browser.
2. Add this to the end of the URL:
   `?conflict_detective_safe_mode=1`
3. Load the page.
4. Log in to wp-admin.
5. Go to Tools, then Conflict Detective.
6. Click “Run scan now”.
7. Wait for it to finish.
8. Look at the “Active conflicts” list.
9. If it points at a plugin, disable that plugin.
10. Exit safe mode using the button at the top.

If the site works after disabling a plugin, that plugin is probably the problem.

Probably. Nothing is certain. But it is usually enough.

## The tiny setup at the end

Do this once.

1. Install Conflict Detective.
2. Activate it.
3. Go to Tools, then Conflict Detective.
4. If you see a safe mode button, you are in the right place.
5. Leave it alone until the day your site breaks.
6. When that day comes, use safe mode and scan.

That is it.
You do not need to touch twenty settings.
You just need a ladder out of the pit when you fall in.
