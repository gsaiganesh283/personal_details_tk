# How to push the website to GitHub

This git repository lives **inside the `website/` folder**, so only the
website's files are tracked. The Python desktop app and everything else outside
`website/` is in a different folder and is never part of this repo.

- **Remote:** https://github.com/gsaiganesh283/personal_details_tk
- **Branch:** `main`
- **Repo root:** `website/` (its contents sit at the top level on GitHub)

## What is NOT pushed (see `.gitignore`)

Secrets and data are excluded on purpose — never force them in:

| Ignored | Why |
|---|---|
| `config/*` (keeps only `config/app-version.json`) | DB password, OAuth secrets, Google Drive tokens, SMTP credentials |
| `Backup/`, `*.sql.gz`, `Backup.sql` | full data dump of the live site |
| `uploads/` | user-uploaded documents / attachments |
| `*.log` | logs |
| `vendor/`, `composer.phar` | dependencies (restored with `composer install`) |

> The `sql/` folder (schema + migrations) **is** tracked — those are code, not data.

## One-time setup (already done)

```bash
cd website
git init
git branch -M main
git remote add origin https://github.com/gsaiganesh283/personal_details_tk.git
```

## Everyday: push your changes

Run these **from inside the `website/` folder**:

```bash
cd website
git status            # review what changed
git add -A            # stage everything (secrets stay excluded by .gitignore)
git commit -m "Describe what you changed"
git push              # or: git push -u origin main  (first time)
```

## Before the very first push, double-check no secrets are staged

```bash
git ls-files | grep -iE 'config/(database|oauth|email|google-drive|desktop-sync)|google-drive-tokens|Backup\.sql|uploads/|\.log$|composer\.phar|vendor/'
```

If that prints **nothing**, you're safe. If it prints a file, stop and add it to
`.gitignore`, then `git rm --cached <file>` before committing.

## If a credential prompt appears

The first push opens Git Credential Manager in your browser to sign in to
GitHub. After that, credentials are cached and pushes are silent.
