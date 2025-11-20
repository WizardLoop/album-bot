# Telegram Album Bot (Webhook & Polling)
## album-bot
A lightweight and powerful Telegram bot that collects **albums** and send them, in a clean, organized way.

Created by **@wizardloop**.

---

## 🚀 Features

- Collect unlimited messages before sending
- Supports **photos, videos, animations, documents, video notes, and more**
- Supports **albums (media groups)**
- Live counter showing how many messages were collected(in webhook version)
- Automatic cleanup after sending
- Webhook & Polling
- Simple, clean PHP code
- Fully deployable on any hosting

---

## 🧩 Requirements

- PHP 7.4+ (for webhook version)
- PHP 8.2+ (for polling version)
- HTTPS-enabled hosting/server (for webhook version)
- Valid Telegram bot token
- Valid Telegram API ID & HASH (for polling version)
---

## 📌 Setup

1. Clone the repository:

```sh
git clone https://github.com/WizardLoop/album-bot
cd album-bot
```

---

## 🌐 Setting Your Webhook

Replace:

- `YOUR_BOT_TOKEN` with your bot token  
- `YOUR_DOMAIN/BOT_PATH/bot.php` with your full file URL  

Run in browser:

```
https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=YOUR_DOMAIN/BOT_PATH/bot.php
```

Example:

```
https://api.telegram.org/bot123456:ABC/setWebhook?url=https://mydomain.com/bot/bot.php
```

To delete webhook:

```
https://api.telegram.org/botYOUR_BOT_TOKEN/deleteWebhook
```

---

## 🤝 Contributing

Pull requests are welcome.  
Please open an issue before major changes.

---

## 📜 License

MIT License © WizardLoop
