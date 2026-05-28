# ISP Billing System – Final Documentation

## Server Information
- **OS**: Ubuntu 24.04.4 LTS
- **IP Address**: 10.12.14.16 (Saudi Arabia, Zain mobile network)
- **Web Server**: nginx/1.24.0 on port 8080
- **Database**: SQLite3 at `/home/tserver/billing_reminder/bills.db`
- **WhatsApp Gateway**: OpenWA (Docker) – API on port 2785, Dashboard on port 2886
- **Movie Server**: `/mnt/bigstorage/files/` served on port 8082

## Directory Structure
/home/tserver/billing_reminder/
├── portal.php # Main billing portal (working)
├── bills.db # SQLite database
├── send_reminders.php # Cron script for 5‑day/2‑day reminders
├── reminder_log.txt # Log of reminder runs
├── BILLING_SYSTEM_FINAL.md # This file
└── various backup files (*.backup)


## OpenWA Configuration

- **Container name**: `openwa`
- **Image**: `ghcr.io/rmyndharis/openwa:latest`
- **Session ID (current)**: `84ecd217-e6bc-4b7e-ac92-1d09cb3f0be7`
- **API Key**: `dev-admin-key`

### Check OpenWA status
```bash
curl -s http://localhost:2785/api/sessions -H "X-API-Key: dev-admin-key" | python3 -m json.tool


Reconnect WhatsApp when session is disconnected (CLI only – preferred method)
Run these commands one by one:
# 1. Get current session ID
SESSION_ID=$(curl -s http://localhost:2785/api/sessions -H "X-API-Key: dev-admin-key" | python3 -c "import sys,json; print(json.load(sys.stdin)[0]['id'])")

# 2. Delete old session
curl -X DELETE http://localhost:2785/api/sessions/$SESSION_ID -H "X-API-Key: dev-admin-key"

# 3. Create a new session
NEW_SESSION=$(curl -s -X POST http://localhost:2785/api/sessions -H "Content-Type: application/json" -H "X-API-Key: dev-admin-key" -d '{"name": "billing-bot"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
echo "New session ID: $NEW_SESSION"

# 4. Start the new session
curl -X POST http://localhost:2785/api/sessions/$NEW_SESSION/start -H "X-API-Key: dev-admin-key"

# 5. Generate QR code image
curl -s http://localhost:2785/api/sessions/$NEW_SESSION/qr -H "X-API-Key: dev-admin-key" | python3 -c "import sys,json,base64; d=json.load(sys.stdin); qr=d['qrCode'].split(',')[1]; open('/home/tserver/openwa/qr.png','wb').write(base64.b64decode(qr))" && echo "QR saved to /home/tserver/openwa/qr.png"

# 6. Copy QR to your desktop (from your local machine, not SSH)
# scp tserver@10.12.14.16:/home/tserver/openwa/qr.png ~/Desktop/

# 7. Update session ID in portal.php and send_reminders.php
sed -i "s/$SESSION_ID/$NEW_SESSION/g" /home/tserver/billing_reminder/portal.php
sed -i "s/$SESSION_ID/$NEW_SESSION/g" /home/tserver/billing_reminder/send_reminders.php

# 8. Verify status
curl -s http://localhost:2785/api/sessions/$NEW_SESSION -H "X-API-Key: dev-admin-key" | python3 -c "import sys,json; print('Status:', json.load(sys.stdin)['status'])"



Scheduled Reminders
Cron job: 0 8 * * * php /home/tserver/billing_reminder/send_reminders.php >> /home/tserver/billing_reminder/reminder_log.txt 2>&1

Runs daily at 8:00 AM (server time Asia/Riyadh)

Sends WhatsApp reminders 5 days and 2 days before each customer's billing day (due_day).

Log file: /home/tserver/billing_reminder/reminder_log.txt


Test reminders manually:
php /home/tserver/billing_reminder/send_reminders.php
tail -20 /home/tserver/billing_reminder/reminder_log.txt







Auto Payment Receipts
When an admin records a payment, sendWhatsAppMessage() is called automatically.

The customer receives a detailed receipt with:

Paid amount and month(s)

Remaining balance

Movie server link

Technical support numbers

Manual Reminder Buttons
Collections page: Green WhatsApp button sends a reminder listing all unpaid months and total due.

Customers page: Same button sends a simple due reminder.

Both use the same OpenWA API.

Troubleshooting
Reminders not sending
Check OpenWA status (must be ready).

Ensure your phone has internet and WhatsApp Web session is alive.

Test API directly:curl -X POST http://localhost:2785/api/sessions/$(curl -s http://localhost:2785/api/sessions -H "X-API-Key: dev-admin-key" | python3 -c "import sys,json; print(json.load(sys.stdin)[0]['id'])")/messages/send-text -H "Content-Type: application/json" -H "X-API-Key: dev-admin-key" -d '{"chatId": "9665xxxxxxxx@c.us", "text": "Test"}'


Check cron logs: grep send_reminders /var/log/syslog

Portal shows error 500
php -l /home/tserver/billing_reminder/portal.php
tail -20 /var/log/nginx/error.log



Check cron logs: grep send_reminders /var/log/syslog

Portal shows error 500
bash
php -l /home/tserver/billing_reminder/portal.php
tail -20 /var/log/nginx/error.log
Restart OpenWA container if stuck
bash
docker restart openwa
docker logs openwa --tail 30
Backup and Restore
Create a full backup tarball
bash
cd /home/tserver && tar -czvf billing_reminder_backup_$(date +%Y%m%d_%H%M%S).tar.gz billing_reminder/
Restore from a previous backup
bash
cp /home/tserver/billing_reminder/portal.php.FINAL_WORKING_BACKUP /home/tserver/billing_reminder/portal.php
Download backup to your desktop (from local terminal)
bash
scp tserver@10.12.14.16:/home/tserver/billing_reminder_backup_*.tar.gz ~/Desktop/
Quick Command Reference
Action	Command
Check OpenWA status	curl -s http://localhost:2785/api/sessions -H "X-API-Key: dev-admin-key" | python3 -m json.tool
Send test message	curl -X POST http://localhost:2785/api/sessions/$SESSION_ID/messages/send-text -H "Content-Type: application/json" -H "X-API-Key: dev-admin-key" -d '{"chatId": "9665xxxxxx@c.us", "text": "test"}'
Run reminders now	php /home/tserver/billing_reminder/send_reminders.php
View reminder log	tail -50 /home/tserver/billing_reminder/reminder_log.txt
Verify PHP syntax	php -l /home/tserver/billing_reminder/portal.php
Pull latest from GitHub (when DNS works)	git pull origin main
Final Working State (as of 2026-05-27)
✅ Billing Day column displayed on Collections page

✅ Dark mode toggle and responsive design

✅ OpenWA integrated – automatic payment receipts and manual reminders

✅ Cron job sends 5‑day and 2‑day reminders daily at 8 AM

✅ Session ID 84ecd217-e6bc-4b7e-ac92-1d09cb3f0be7 is active

✅ CLI procedure documented for reconnecting WhatsApp when needed

⚠️ GitHub push currently blocked by DNS (use local SCP or fix network)

Important Notes
The WhatsApp session requires the connected phone to have continuous internet. If the phone goes offline, the session will disconnect and no messages will be sent until you reconnect using the CLI procedure above.

No web‑based QR manager is used; all management is done via SSH/CLI.

All backups are stored locally; you can also download them via SCP.

*Documentation completed on 2026-05-28 – reflects the final stable state of the ISP Billing System.*

text

## Step 3: Save and exit

Press `Ctrl+O`, then `Enter`, then `Ctrl+X`.

## Step 4: Verify the file is complete

```bash
wc -l /home/tserver/billing_reminder/BILLING_SYSTEM_FINAL.md
tail -5 /home/tserver/billing_reminder/BILLING_SYSTEM_FINAL.md
Step 5: Add to Git (optional, for when DNS works)
bash
cd /home/tserver/billing_reminder
git add BILLING_SYSTEM_FINAL.md
git commit -m "Final system documentation including OpenWA, cron, reconnection procedure"





































