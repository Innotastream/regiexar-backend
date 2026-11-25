<?php
declare(strict_types=1);

require_once __DIR__.'/map-animation.php';

const XAR_MAP_ANIMATION_RECIPES_MAX_BYTES = 32768;

function applicationMapAnimationRecipeText(mixed $value, int $maximum): ?string
{
    if (!is_string($value) || preg_match('/^[^\p{C}\p{Zl}\p{Zp}]{1,'.$maximum.'}$/uD', $value) !== 1) return null;
    $text = preg_replace('/^\p{Zs}+|\p{Zs}+$/u', '', $value);
    return is_string($text) && preg_match('/[\p{L}\p{N}]/u', $text) === 1 ? $text : null;
}

function applicationMapAnimationRecipeLatinFold(string $text): string
{
    static $replacements = null;
    if ($replacements === null) {
        $replacements = ['Œ' => 'œ', 'ẞ' => 'ß'];
        // Precomposed Latin characters with diacritics, for PHP hosts without intl.
        foreach ([
            'a' => 'ÀÁÂÃÄÅàáâãäåĀāĂăĄąǍǎǞǟǠǡǺǻȀȁȂȃȦȧḀḁẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵẶặÅ',
            'b' => 'ḂḃḄḅḆḇ',
            'c' => 'ÇçĆćĈĉĊċČčḈḉ',
            'd' => 'ĎďḊḋḌḍḎḏḐḑḒḓ',
            'e' => 'ÈÉÊËèéêëĒēĔĕĖėĘęĚěȄȅȆȇȨȩḔḕḖḗḘḙḚḛḜḝẸẹẺẻẼẽẾếỀềỂểỄễỆệ',
            'f' => 'Ḟḟ',
            'g' => 'ĜĝĞğĠġĢģǦǧǴǵḠḡ',
            'h' => 'ĤĥȞȟḢḣḤḥḦḧḨḩḪḫẖ',
            'i' => 'ÌÍÎÏìíîïĨĩĪīĬĭĮįİǏǐȈȉȊȋḬḭḮḯỈỉỊị',
            'j' => 'Ĵĵǰ',
            'k' => 'ĶķǨǩḰḱḲḳḴḵK',
            'l' => 'ĹĺĻļĽľḶḷḸḹḺḻḼḽ',
            'm' => 'ḾḿṀṁṂṃ',
            'n' => 'ÑñŃńŅņŇňǸǹṄṅṆṇṈṉṊṋ',
            'o' => 'ÒÓÔÕÖòóôõöŌōŎŏŐőƠơǑǒǪǫǬǭȌȍȎȏȪȫȬȭȮȯȰȱṌṍṎṏṐṑṒṓỌọỎỏỐốỒồỔổỖỗỘộỚớỜờỞởỠỡỢợ',
            'p' => 'ṔṕṖṗ',
            'r' => 'ŔŕŖŗŘřȐȑȒȓṘṙṚṛṜṝṞṟ',
            's' => 'ŚśŜŝŞşŠšȘșṠṡṢṣṤṥṦṧṨṩ',
            't' => 'ŢţŤťȚțṪṫṬṭṮṯṰṱẗ',
            'u' => 'ÙÚÛÜùúûüŨũŪūŬŭŮůŰűŲųƯưǓǔǕǖǗǘǙǚǛǜȔȕȖȗṲṳṴṵṶṷṸṹṺṻỤụỦủỨứỪừỬửỮữỰự',
            'v' => 'ṼṽṾṿ',
            'w' => 'ŴŵẀẁẂẃẄẅẆẇẈẉẘ',
            'x' => 'ẊẋẌẍ',
            'y' => 'ÝýÿŶŷŸȲȳẎẏẙỲỳỴỵỶỷỸỹ',
            'z' => 'ŹźŻżŽžẐẑẒẓẔẕ',
            'æ' => 'ÆǢǣǼǽ',
            'ø' => 'ØǾǿ',
        ] as $plain => $characters) {
            foreach (preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $replacements[$character] = $plain;
            }
        }
    }
    return strtr($text, $replacements);
}

function applicationMapAnimationRecipeSearchKey(string $text): string
{
    $normalized = class_exists('Normalizer') ? Normalizer::normalize($text, Normalizer::FORM_D) : false;
    $text = applicationMapAnimationRecipeLatinFold(is_string($normalized) ? $normalized : $text);
    $text = preg_replace('/\p{M}+/u', '', $text) ?? $text;
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '');
}

function applicationMapAnimationRecipeTexts(mixed $items, int $maximumItems, int $maximumLength): ?array
{
    if (!is_array($items) || !array_is_list($items) || count($items) > $maximumItems) return null;
    $result = [];
    foreach ($items as $item) {
        $text = applicationMapAnimationRecipeText($item, $maximumLength);
        if ($text === null) return null;
        $result[] = $text;
    }
    return $result;
}

function applicationNormalizeMapAnimationRecipeCatalog(mixed $raw): ?array
{
    if (!is_array($raw) || array_diff(array_keys($raw), ['version', 'guidance', 'recipes'])
        || ($raw['version'] ?? null) !== 1 || !is_array($raw['recipes'] ?? null)
        || !array_is_list($raw['recipes']) || count($raw['recipes']) > 16) return null;
    $guidance = applicationMapAnimationRecipeTexts(
        array_key_exists('guidance', $raw) ? $raw['guidance'] : [], 4, 180
    );
    if ($guidance === null) return null;

    $ids = [];
    $termOwners = [];
    $recipes = [];
    $layerKeys = ['kind', 'intensity', 'speed', 'direction', 'fish', 'tint', 'cycleSeconds'];
    foreach ($raw['recipes'] as $entry) {
        if (!is_array($entry) || array_diff(array_keys($entry), ['id', 'label', 'aliases', 'layers'])) return null;
        $id = $entry['id'] ?? null;
        $label = applicationMapAnimationRecipeText($entry['label'] ?? null, 72);
        $aliases = applicationMapAnimationRecipeTexts($entry['aliases'] ?? null, 8, 72);
        $layers = $entry['layers'] ?? null;
        if (!is_string($id) || strlen($id) > 48 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) !== 1
            || isset($ids[$id]) || $label === null || $aliases === null || !$aliases
            || !is_array($layers) || !array_is_list($layers) || count($layers) < 1 || count($layers) > 6) return null;
        $ids[$id] = true;
        foreach ([$label, ...$aliases] as $term) {
            $key = applicationMapAnimationRecipeSearchKey($term);
            if ($key === '' || (isset($termOwners[$key]) && $termOwners[$key] !== $id)) return null;
            $termOwners[$key] = $id;
        }

        $kinds = [];
        $normalizedLayers = [];
        foreach ($layers as $layer) {
            if (!is_array($layer) || array_diff(array_keys($layer), $layerKeys)) return null;
            $settings = applicationMapAnimationLayerSettings($layer);
            if ($settings === null || isset($kinds[$settings['kind']])) return null;
            $kinds[$settings['kind']] = true;
            $normalizedLayers[] = $settings;
        }
        $recipes[] = ['id' => $id, 'label' => $label, 'aliases' => $aliases, 'layers' => $normalizedLayers];
    }
    $catalog = ['version' => 1, 'guidance' => $guidance, 'recipes' => $recipes];
    $revision = hash('sha256', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return [...$catalog, 'revision' => $revision];
}

function applicationReadMapAnimationRecipeCatalog(): ?array
{
    $json = @file_get_contents(__DIR__.'/map-animation-recipes.json', false, null, 0, XAR_MAP_ANIMATION_RECIPES_MAX_BYTES + 1);
    if (!is_string($json) || strlen($json) > XAR_MAP_ANIMATION_RECIPES_MAX_BYTES) return null;
    return applicationNormalizeMapAnimationRecipeCatalog(json_decode($json, true, 12));
}

function applicationMapAnimationRecipeEtagMatches(string $value, string $tag): bool
{
    if (strlen($value) > 1024) return false;
    foreach (explode(',', $value) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*' || $candidate === $tag || $candidate === 'W/'.$tag) return true;
    }
    return false;
}

function serveMapAnimationRecipes(PDO $connection, string $method, bool $headOnly): never
{
    requireMethod($method, ['GET', 'HEAD']);
    requireGmIdentity($connection);
    $catalog = applicationReadMapAnimationRecipeCatalog();
    if ($catalog === null) sendError(503, 'Le catalogue d’animations est indisponible.', 'map_animation_catalog_unavailable');
    $tag = '"'.$catalog['revision'].'"';
    $headers = [
        'Cache-Control' => 'private, no-cache',
        'ETag' => 'W/'.$tag,
        'Vary' => 'Authorization, Cookie, X-Xar-Session, X-Xar-Client-Version',
    ];
    if (applicationMapAnimationRecipeEtagMatches((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $tag)) {
        sendJson(304, [], true, $headers);
    }
    sendJson(200, ['ok' => true, ...$catalog], $headOnly, $headers);
}
