# TYPO3 Content Radar

Lightweight TYPO3 extension to identify outdated and potentially neglected pages in your installation.

---

## 🚧 Status

Early development (v0.1.0)

This extension is under active development.
APIs and features may change.

---

## ✨ Idea

TYPO3 installations tend to grow over time.
Content becomes outdated, forgotten, or loses relevance.

Content Radar aims to make this visible.

---

## 🔍 Current Features

- Lists all pages from the TYPO3 database
- Calculates page age (based on last modification)
- Detects orphan/leaf pages (no child pages)
- Calculates a content score (0–100) based on age and structure
- Simple status classification:
    - 🟢 up to date
    - 🟡 aging
    - 🔴 outdated
- Basic filtering and sorting
- Detail view per page
- CSV export for the current dataset
- Configurable status thresholds via extension configuration

---

## 🧠 Planned Features

- Duplicate content hints
- CLI commands for automation
- Better duplicate scoring and explanations

---

## 📦 Installation (Development)

Clone into your TYPO3 project:

```bash
git clone https://github.com/mobasoft/typo3-content-radar.git packages/content_radar
```

Add repository to your root composer.json:

```json
"repositories": [   {     "type": "path",     "url": "packages/*",     "options": {       "symlink": true     }   } ]
```

Require the extension:

```bash
composer require mobasoft/typo3-content-radar:@dev
```

Then activate it in the TYPO3 backend.

---

## ⚙️ Requirements

- TYPO3 v13
- Composer-based installation

---

## ⚙️ Configuration

You can override the age thresholds via TYPO3 extension configuration:

- `yellowThreshold`: days after which a page becomes yellow
- `redThreshold`: days after which a page becomes red

Defaults are `180` and `365` days.

---

## 🎯 Goal

Provide a simple, practical tool for editors and developers to:

- detect outdated content
- improve content quality
- maintain large TYPO3 installations more effectively

---

## 🤝 Contributing

Feedback, ideas and pull requests are welcome.

Please keep changes focused and pragmatic.

---

## 📄 License

MIT License
