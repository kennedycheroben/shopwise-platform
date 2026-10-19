<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
http_response_code(404);
require dirname(__DIR__, 2) . '/app/views/admin/error-404.php';
