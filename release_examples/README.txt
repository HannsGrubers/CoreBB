CoreBB configuration examples

Do not put live passwords or secrets in the public repository.

Recommended shared-host layout:
  /home/account/public_html/forum/        CoreBB public files
  /home/account/corebb_private/main_forum/config.live.php

Use corebb_private_config.example.php as a starting point for the private
config file. The web installer can also generate the correct private config for
new installs.
