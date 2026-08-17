<?php
/**
 * Обертка над DOMDocument с небольшим CSS-селектором.
 *
 * Документ разбирается ровно один раз на страницу — раньше он строился
 * заново в каждом методе разбора, что и съедало время и память.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Dom {

    /** @var DOMDocument|null */
    private $doc = null;

    /** @var DOMXPath|null */
    private $xpath = null;

    /** @var string */
    private $html = '';

    /** @var array кеш скомпилированных селекторов */
    private static $xpath_cache = array();

    public function __construct($html) {
        $this->html = (string) $html;
        $this->load();
    }

    private function load() {
        if (trim($this->html) === '') { return; }

        $html = $this->html;
        // Скрипты и стили только мешают текстовому разбору и раздувают дерево.
        $html = preg_replace('~<(script|style|noscript|iframe|svg)\b[^>]*>.*?</\1>~is', ' ', $html);
        // Кроме JSON-LD: его возвращаем обратно, он самый ценный источник данных.
        if (preg_match_all('~<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>.*?</script>~is', $this->html, $m)) {
            $html .= "\n" . implode("\n", $m[0]);
        }
        $html = preg_replace('~<!--.*?-->~s', ' ', $html);

        $previous = libxml_use_internal_errors(true);
        if (function_exists('libxml_disable_entity_loader') && PHP_VERSION_ID < 80000) {
            @libxml_disable_entity_loader(true);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $ok  = @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok) {
            $this->doc   = $doc;
            $this->xpath = new DOMXPath($doc);
        }
    }

    public function ok() {
        return $this->doc !== null;
    }

    public function raw_html() {
        return $this->html;
    }

    /**
     * Поиск по CSS-селектору. Поддерживается практичное подмножество:
     * tag, .class, #id, [attr], [attr="v"], [attr*="v"], [attr^="v"], [attr$="v"],
     * потомки через пробел, прямые дети через >, группы через запятую.
     *
     * @return DOMElement[]
     */
    public function find($selector, DOMNode $context = null) {
        if (!$this->xpath) { return array(); }

        $expression = self::compile($selector);
        if ($expression === '') { return array(); }

        $nodes = $context ? @$this->xpath->query($expression, $context) : @$this->xpath->query($expression);
        if (!$nodes) { return array(); }

        $out = array();
        foreach ($nodes as $node) { $out[] = $node; }
        return $out;
    }

    public function first($selector, DOMNode $context = null) {
        $nodes = $this->find($selector, $context);
        return $nodes ? $nodes[0] : null;
    }

    public function tags($name) {
        if (!$this->doc) { return array(); }
        $out = array();
        foreach ($this->doc->getElementsByTagName($name) as $node) { $out[] = $node; }
        return $out;
    }

    /**
     * Текст первого совпавшего узла.
     */
    public function text($selector, DOMNode $context = null) {
        $node = $this->first($selector, $context);
        return $node ? self::node_text($node) : '';
    }

    public function attr($selector, $attribute, DOMNode $context = null) {
        $node = $this->first($selector, $context);
        if (!$node || !$node instanceof DOMElement) { return ''; }
        return trim($node->getAttribute($attribute));
    }

    /**
     * Значение meta-тега по name или property.
     */
    public function meta($key) {
        foreach (array('property', 'name', 'itemprop') as $attribute) {
            $node = $this->first('meta[' . $attribute . '="' . $key . '"]');
            if ($node instanceof DOMElement) {
                $content = trim($node->getAttribute('content'));
                if ($content !== '') { return $content; }
            }
        }
        return '';
    }

    /** Теги, вокруг которых при склейке текста нужен пробел. */
    private static $block_tags = array(
        'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    );

    /**
     * Текст узла. В отличие от textContent вставляет пробел на границах
     * блочных элементов: иначе «<td>Сечение</td><td>2.5</td>» слипается
     * в «Сечение2.5» и характеристика разбирается неверно.
     */
    public static function node_text($node) {
        if (!$node) { return ''; }
        if (!$node instanceof DOMNode) { return UWP_Dom::clean((string) $node); }

        return UWP_Dom::clean(self::walk_text($node, 0));
    }

    private static function walk_text(DOMNode $node, $depth) {
        if ($depth > 30) { return ''; }

        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) $node->nodeValue;
        }
        if (!$node->hasChildNodes()) {
            return in_array(strtolower($node->nodeName), self::$block_tags, true) ? ' ' : '';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $is_block = $child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->nodeName), self::$block_tags, true);
            if ($is_block) { $text .= ' '; }
            $text .= self::walk_text($child, $depth + 1);
            if ($is_block) { $text .= ' '; }
        }
        return $text;
    }

    public static function node_html($node) {
        if (!$node instanceof DOMNode || !$node->ownerDocument) { return ''; }
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    public static function clean($text) {
        if ($text === null) { return ''; }
        if (is_array($text)) { $text = implode(' ', $text); }
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\xc2\xa0", "\xe2\x80\x8b", "\t"), ' ', $text);
        $text = preg_replace('~\s+~u', ' ', $text);
        return trim((string) $text);
    }

    /**
     * CSS → XPath.
     */
    public static function compile($selector) {
        $selector = trim((string) $selector);
        if ($selector === '') { return ''; }
        if (isset(self::$xpath_cache[$selector])) { return self::$xpath_cache[$selector]; }

        $groups = array();
        foreach (self::split_groups($selector) as $group) {
            $expression = self::compile_group($group);
            if ($expression !== '') { $groups[] = $expression; }
        }

        $result = implode(' | ', $groups);
        if (count(self::$xpath_cache) < 200) { self::$xpath_cache[$selector] = $result; }
        return $result;
    }

    private static function split_groups($selector) {
        $groups  = array();
        $buffer  = '';
        $depth   = 0;
        $quote   = '';
        $length  = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($quote !== '') {
                if ($char === $quote) { $quote = ''; }
                $buffer .= $char;
                continue;
            }
            if ($char === '"' || $char === "'") { $quote = $char; $buffer .= $char; continue; }
            if ($char === '[') { $depth++; }
            if ($char === ']') { $depth--; }
            if ($char === ',' && $depth <= 0) { $groups[] = $buffer; $buffer = ''; continue; }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') { $groups[] = $buffer; }
        return $groups;
    }

    private static function compile_group($group) {
        $group = trim(preg_replace('~\s*>\s*~', ' > ', $group));
        if ($group === '') { return ''; }

        $tokens = preg_split('~\s+~', $group);
        $xpath  = '';
        $axis   = '//';

        foreach ($tokens as $token) {
            if ($token === '') { continue; }
            if ($token === '>') { $axis = '/'; continue; }

            $step = self::compile_token($token);
            if ($step === '') { return ''; }

            $xpath .= $axis . $step;
            $axis   = '//';
        }

        return $xpath;
    }

    private static function compile_token($token) {
        $tag        = '*';
        $conditions = array();

        if (preg_match('~^([a-zA-Z][\w-]*)~', $token, $m)) {
            $tag = strtolower($m[1]);
        }

        if (preg_match_all('~\.([\w-]+)~', $token, $m)) {
            foreach ($m[1] as $class) {
                $conditions[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . self::quote_inner($class) . ' ")';
            }
        }

        if (preg_match('~#([\w-]+)~', $token, $m)) {
            $conditions[] = '@id=' . self::quote($m[1]);
        }

        // Делимитр не «~»: этот символ входит в набор операторов сравнения атрибутов.
        if (preg_match_all('#\[([\w:.-]+)\s*(?:([*^$~|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]]*)))?\]#', $token, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $attribute = '@' . $match[1];
                $operator  = isset($match[2]) ? $match[2] : '';
                if ($operator === '') { $conditions[] = $attribute; continue; }

                $value = '';
                foreach (array(3, 4, 5) as $index) {
                    if (isset($match[$index]) && $match[$index] !== '') { $value = $match[$index]; break; }
                }
                if ($value === '') { $conditions[] = $attribute; continue; }
                $quoted = self::quote($value);

                switch ($operator) {
                    case '*=':
                        $conditions[] = 'contains(' . $attribute . ', ' . $quoted . ')';
                        break;
                    case '^=':
                        $conditions[] = 'starts-with(' . $attribute . ', ' . $quoted . ')';
                        break;
                    case '$=':
                        $conditions[] = 'substring(' . $attribute . ', string-length(' . $attribute . ') - ' . (strlen($value) - 1) . ') = ' . $quoted;
                        break;
                    case '~=':
                        $conditions[] = 'contains(concat(" ", normalize-space(' . $attribute . '), " "), ' . self::quote(' ' . $value . ' ') . ')';
                        break;
                    default:
                        $conditions[] = $attribute . '=' . $quoted;
                        break;
                }
            }
        }

        return $tag . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
    }

    private static function quote($value) {
        if (strpos($value, "'") === false) { return "'" . $value . "'"; }
        if (strpos($value, '"') === false) { return '"' . $value . '"'; }
        return 'concat(' . "'" . str_replace("'", "', \"'\", '", $value) . "'" . ')';
    }

    private static function quote_inner($value) {
        return str_replace(array("'", '"'), '', $value);
    }
}
