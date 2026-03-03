# Development Guidelines

## Project Context
This project is a WordPress Update Server adapted for AWS Lambda using the Bref PHP runtime. It serves plugin and theme updates, using S3 as a package host instead of local storage.

## Development Principles
- **Prefer IDE and MCP Tools:** Before reaching for the command line or terminal utilities, always check if there is an equivalent MCP tool or IDE-integrated feature. Using these tools ensures better context awareness and consistency within the development environment.
- **Serverless First:** Keep in mind that the application runs in a read-only environment (AWS Lambda), except for the `/tmp` directory. All persistent storage should be offloaded to S3.
- **Minimal Dependencies:** Maintain the project's goal of minimal server requirements, while acknowledging the need for the AWS SDK for S3 support.

## Project Parameters
- **Runtime:** PHP 8.4 (Bref FPM)
- **Deployment:** AWS Lambda via Serverless Framework (Bref)
- **Package Storage:** AWS S3
- **Local entry point:** `index.php`
- **Core logic:** `includes/Wpup/UpdateServer.php`

## Tooling Preferences
- **Code Editing:** Use the provided editing tools (`search_replace`, `multi_edit`) for all code modifications.
- **Search:** Use `search_project` for finding symbols or text.
- **Structure:** Use `get_file_structure` to understand file layouts before editing.
- **Terminal:** Use the terminal only when necessary for running tests or specific CLI-only tools that don't have MCP equivalents.
