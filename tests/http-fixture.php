<?php
/** Servidor local de respostas HTTP controladas para os testes de monitoramento. */
$code = (int) ($_GET['code'] ?? 200);
http_response_code($code >= 100 && $code <= 599 ? $code : 500);
