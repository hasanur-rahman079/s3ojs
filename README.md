# S3 Storage Plugin for OJS 3.5

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![OJS Version](https://img.shields.io/badge/OJS-3.5.0--x-green.svg)](https://pkp.sfu.ca/ojs/)

Enhance your Open Journal Systems (OJS) installation with seamless S3-compatible cloud storage integration. This plugin extends the OJS filesystem to support high-availability storage providers like AWS S3, Wasabi, DigitalOcean Spaces, and more.

## 🌟 Key Features

### 🛡️ High Availability & Reliability
- **Hybrid Storage Engine**: Automatically manages files across both cloud and local storage.
- **Fail-safe Fallback**: Intelligent error handling ensures OJS continues to function using local storage if the cloud provider is unreachable.
- **Redundancy**: Option to keep files stored both locally and on the cloud simultaneously.

### ⚡ Performance & Efficiency
- **Direct S3 Serving**: Redirect file downloads to presigned S3 URLs, reducing server load and bandwidth costs.
- **Storage Optimization**: Automatically removes local files after successful cloud synchronization to minimize server disk usage.

### 🛠️ Advanced Management
- **One-Click Restoration**: Built-in tool to restore your entire library from S3 to local storage in case of server migration or disaster recovery.
- **Secure Maintenance**: Daily automated tasks for synchronization, health checks, and secure, scoped cleanup of orphaned files.

## 📋 Requirements

- **OJS Version**: 3.5.0-x
- **PHP**: 8.1 or higher
- **PHP Extensions**: `curl`, `simplexml`, `mbstring`
- **Storage Provider**: Any S3-compatible service (AWS, Wasabi, DigitalOcean, etc.)

## 🚀 Installation

1.  **Extract**: Upload the `s3ojs` directory to your OJS installation at `plugins/generic/s3ojs`.
2.  **Dependencies**: Ensure the `vendor` directory is present (pre-packaged dependencies included).
3.  **Activate**:
    - Log in to your OJS site as a **Site Administrator**.
    - Navigate to **Administration > Site Settings > Plugins**.
    - Locate and enable the **S3 Storage Plugin**.
4.  **Configure**: Click the blue arrow next to the plugin name and select **Settings**.

## ⚙️ Configuration

| Setting | Description |
|:---|:---|
| **S3 Provider** | Select your provider or use 'Custom' for S3-compatible services. |
| **S3 Bucket** | Your unique bucket name. |
| **Access Key & Secret** | Your provider-issued API credentials. |
| **Direct Serving** | When enabled, users download files directly from S3 (via signed URLs). |
| **Hybrid Mode** | Keeps a copy of all new files on both the server and S3. |
| **Save Storage** | Automatically deletes local copies after cloud upload. |

## 🕒 Maintenance & Scheduled Tasks

The plugin registers a background task that runs daily at **midnight (00:00)** to ensure your storage remains healthy and in sync.

- **Automated Sync**: Acts as a safety net to upload any files missed during initial submission.
- **Secure Cleanup**: Safely identifies and removes "orphaned" files in S3 that no longer exist in the OJS database.
- **Health Checks**: Performs connection tests and logs status reports to `scheduledTaskLogs/`.

## 🏥 Disaster Recovery & Local Mirroring

The plugin works immediately upon configuration, serving files directly from the S3 cloud. However, if you wish to maintain a local mirror of your files, the restoration tool is available.

### Restoring from Cloud to Local
Use this if you want to keep files locally or if you have migrated your OJS installation:
1.  **Direct serving is automatic**: Once configured, S3 cloud serving works immediately.
2.  **Optional Local Copy**: If you prefer to have a copy on your local server, navigate to the **Restore from S3** section in the plugin settings.
3.  **Start Restoration**: Click the button to reconstruct your local `files_dir` structure from the cloud.

## 📄 License

This plugin is licensed under the GNU General Public License v3. See the [LICENSE](LICENSE) file for details.

---
*Developed for the PKP Community by [Md. Hasanur Rahman](https://github.com/hasanur-rahman079).*