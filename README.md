# Frontier

WordPress plugin for site procurement, from BOQ and indents through purchase orders, goods receipt and invoice matching.

## WordPress deployment

Connect this repository to **Deployer for Git** as a plugin and select `main`. Use the existing `frontier` plugin folder when connecting an installation. Copy the Frontier package’s Push-to-Deploy URL into the repository’s Actions secret named `DFG_PLUGIN_DEPLOY_URL`.

On pushes to `main`, the workflow checks PHP syntax and then requests deployment. Once the secret is configured, use the workflow’s **Run workflow** button to verify the connection without a new commit. Confirm the result in Deployer for Git → Logs.
