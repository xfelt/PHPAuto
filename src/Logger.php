<?php

class Logger {
    public static function logToFile($filename, $content) {
        file_put_contents($filename, $content, FILE_APPEND);
    }
}
