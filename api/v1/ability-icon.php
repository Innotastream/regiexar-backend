<?php
declare(strict_types=1);

function applicationAbilityIcon(mixed $value): ?array
{
    if (!is_array($value) || !is_string($value['url'] ?? null)) return null;
    $url = trim($value['url']);
    if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00\r\n]/', $url)) return null;
    $local = preg_match('#^/media/[A-Za-z0-9_-]{24}$#D', $url) === 1;
    if (!$local) {
        $parts = strlen($url) <= 2048 ? parse_url($url) : false;
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'regie-xar-tsaroth.fr'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    }
    return ['url' => $url, 'label' => applicationComplexAbilityText($value['label'] ?? '', 120, 'Compétence'), 'durationMs' => 2000];
}
