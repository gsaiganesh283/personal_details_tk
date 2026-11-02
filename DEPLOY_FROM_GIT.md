# Deploying the website from GitHub (cPanel, no Terminal)

How to update **https://pds.pdsonline.in** from the GitHub repo, and how to fix
the common "local changes would be overwritten" deploy error — all from the
cPanel web interface (this host has no Terminal).

## Your setup (for reference)

| Thing | Value |
|---|---|
| GitHub repo | https://github.com/gsaiganesh283/personal_details_tk |
| Branch | `main` |
| Server repo path | `/home/qbgdvqgo/pds` |
| Document root | `/home/qbgdvqgo/pds` (the site runs **directly** from the repo) |

Because the repo folder *is* the document root, pulling from GitHub updates the
live site immediately — there is no separate "build" or copy step.

---

## A. Normal update (when there is NO conflict)

1. Push your changes from your PC first (see section C).
2. cPanel → **Git™ Version Control**.
3. On the `pds` repository, click **Manage**.
4. Open the **Pull or Deploy** tab.
5. Click **Update from Remote**.
6. Wait for "success". The live site now matches GitHub.

If it shows an error like *"Your local changes to the following files would be
overwritten by merge … Aborting"*, use section B instead.

---

## B. Fix the "local changes would be overwritten" error

This happens when the server's copy of a tracked file differs from GitHub
(often just line-ending differences). cPanel has no button to discard those, so
run a one-time PHP helper.

1. cPanel → **File Manager** → go to the folder `/home/qbgdvqgo/pds`
   (it contains `.git`, `assets`, `config`, `.htaccess`, the `.php` files).
2. Click **+ File**, name it **`git-fix.php`**, and make sure it is created in
   **this** folder (the breadcrumb/path must read `/home/qbgdvqgo/pds`).
3. Select `git-fix.php` → **Edit** → paste exactly:

   ```php
   <?php
   header('Content-Type: text/plain');
   $git  = is_executable('/usr/local/cpanel/3rdparty/bin/git') ? '/usr/local/cpanel/3rdparty/bin/git' : 'git';
   $repo = '/home/qbgdvqgo/pds';
   if (!function_exists('shell_exec')) { exit("shell_exec is disabled on this host.\n"); }
   if (!is_dir("$repo/.git")) { exit("No .git at: $repo\n"); }
   $cd = 'cd ' . escapeshellarg($repo) . ' && ';
   echo "Repo: $repo\n\n";
   echo "--- fetch ---\n"  . shell_exec($cd . escapeshellarg($git) . ' fetch origin 2>&1');
   echo "\n--- reset --hard origin/main ---\n" . shell_exec($cd . escapeshellarg($git) . ' reset --hard origin/main 2>&1');
   echo "\n--- status ---\n" . shell_exec($cd . escapeshellarg($git) . ' status 2>&1');
   echo "\nDONE. Delete git-fix.php now.\n";
   ```

4. **Save**, then open **https://pds.pdsonline.in/git-fix.php** in a browser.
   - Success: `HEAD is now at <hash> …` and `working tree clean`.
   - `shell_exec is disabled`: this method is blocked — see section D.
   - `404 Not Found`: the file is not in `/home/qbgdvqgo/pds`; recreate it there.
5. **Delete `git-fix.php`** immediately (never leave it on a live site).

`reset --hard` only touches files that git tracks. Your real secrets and data
(`config/`, `uploads/`) are **untracked**, so they are left untouched.

---

## C. Push your local changes to GitHub (from your PC)

Run these from **inside the `website/` folder** on your computer:

```bash
cd H:\personal_details_tk\website
git status                 # review what changed
git add <specific files>   # prefer naming files over "git add -A"
git commit -m "Describe the change"
git push
```

> ⚠️ The current `.gitignore` only ignores `config/*`. That means `Backup.sql`,
> `uploads/`, and `error.log` are NOT ignored, so `git add -A` would commit your
> real personal data. Always check `git status` and add specific files.

---

## D. If `shell_exec` is disabled (fallback)

Re-clone the repo cleanly from GitHub:

1. cPanel → **Git Version Control** → remove the `pds` repository entry
   (this unlinks git; it does **not** delete your files).
2. cPanel → **File Manager**: in `/home/qbgdvqgo/pds`, delete only the stuck
   tracked files it complained about (e.g. `.gitignore`), **not** `config/` or
   `uploads/`.
3. Git Version Control → **Create** → **Clone a Repository**:
   - Clone URL: `https://github.com/gsaiganesh283/personal_details_tk.git`
   - Repository Path: a NEW empty folder, e.g. `/home/qbgdvqgo/pds-new`
4. Copy the freshly cloned files into `/home/qbgdvqgo/pds` (keeping your
   existing `config/` and `uploads/`), or point the domain's document root at
   the new folder and copy `config/`+`uploads/` into it.

If you reach this step, tell me and I'll give exact click-by-click moves for
your layout.

---

## What is never deployed/committed

Protected by `.gitignore` and/or `.htaccess`:

- `config/*` (DB password, OAuth, Google tokens) — except `config/app-version.json`
- `Backup/`, `*.sql` dumps, `uploads/`, `*.log`, `vendor/`, `composer.phar`
