<?php
// The app has no public page: send visitors to the admin.
header('Location: admin/', true, 302);
