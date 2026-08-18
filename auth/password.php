<?php
function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}
