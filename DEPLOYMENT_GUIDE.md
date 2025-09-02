# Laravel Backend Deployment Guide for Hostinger

## 🚀 Deployment Steps

### 1. Create Subdomain in Hostinger
- Go to hPanel → Domains → Subdomains
- Create subdomain: `api.yourdomain.com`
- Point to folder: `public_html/api`

### 2. Database Setup
- Create a MySQL database in hPanel
- Note down: database name, username, password, host

### 3. Upload Files
Upload these files to your subdomain folder (`public_html/api/`):

**Required Files:**
- `app/` (entire folder)
- `bootstrap/` (entire folder)
- `config/` (entire folder)
- `database/` (entire folder)
- `public/` (entire folder)
- `resources/` (entire folder)
- `routes/` (entire folder)
- `storage/` (entire folder)
- `vendor/` (entire folder)
- `artisan`
- `composer.json`
- `composer.lock`

### 4. Environment Configuration
Create `.env` file in the root directory with:

```env
APP_NAME="Cody Banks Backend"
APP_ENV=production
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_username
DB_PASSWORD=your_database_password

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### 5. Generate Application Key
Run this command in your local terminal:
```bash
php artisan key:generate --show
```
Copy the generated key and replace `YOUR_APP_KEY_HERE` in your `.env` file.

### 6. Database Migration
- Access your subdomain via SSH or File Manager
- Run: `php artisan migrate`
- Run: `php artisan db:seed`

### 7. Set Permissions
Set these permissions via File Manager:
- `storage/` folder: 755
- `bootstrap/cache/` folder: 755

### 8. Update Frontend API Configuration
In your frontend `src/config/api.js`, update:
```javascript
production: {
  baseURL: 'https://api.yourdomain.com/api',
  heroStatsURL: 'https://mlbb-stats.ridwaanhall.com/api/hero-rank/?days=7&rank=mythic&size=50&index=1&sort_field=win_rate&sort_order=desc'
}
```

### 9. CORS Configuration
Update `config/cors.php` to allow your frontend domain:
```php
'allowed_origins' => [
    'https://yourdomain.com',
    'https://www.yourdomain.com',
],
```

### 10. Test API
Visit: `https://api.yourdomain.com/api/` to test if the API is working.

## 🔧 Troubleshooting

### Common Issues:
1. **500 Error**: Check `.env` file and permissions
2. **Database Connection**: Verify database credentials
3. **CORS Issues**: Update CORS configuration
4. **File Permissions**: Ensure storage folder is writable

### File Structure on Server:
```
public_html/
├── api/                    # Your Laravel backend
│   ├── app/
│   ├── public/
│   │   └── index.php      # Entry point
│   ├── .env
│   └── ...
└── (frontend files)        # Your React frontend
    ├── index.html
    ├── static/
    └── ...
```

## 📝 Notes
- Make sure PHP version is 8.1+ on Hostinger
- Enable mod_rewrite in Apache
- Consider using SSL certificate for both domains
