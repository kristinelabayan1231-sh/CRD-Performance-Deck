<?php

namespace App\Models;

use Database\Factories\SpielFactory;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own spiel (Settings → My Spiels). {name} and {product} in the body are filled in for the customer when it is
 * copied from the Segmentation Tracker's Spiels panel.
 */
#[Fillable(['user_id', 'title', 'body', 'position'])]
class Spiel extends Model
{
    /** @use HasFactory<SpielFactory> */
    use HasFactory;

    /** Filled in when copied: the customer's first name and the lead's product. */
    public const PLACEHOLDERS = ['{name}', '{product}'];

    /** The formatting a spiel keeps (the editor's toolbar); any other tag is unwrapped to its text. */
    public const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'br', 'p', 'div', 'ul', 'ol', 'li'];

    /** Dropped with everything inside them. */
    private const DROPPED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link'];

    /**
     * The editor's HTML with only ALLOWED_TAGS and no attributes, safe to show as is.
     */
    public static function sanitize(string $html): string
    {
        $document = HTMLDocument::createFromString('<!doctype html><body>'.$html.'</body>', LIBXML_NOERROR);
        $body = $document->body;
        self::clean($body);

        return trim($body->innerHTML);
    }

    /**
     * Whether the HTML has any text (not just empty tags and spaces).
     */
    public static function hasText(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\u{A0}") !== '';
    }

    private static function clean(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Element) {
                $tag = strtolower($child->localName);

                if (in_array($tag, self::DROPPED_TAGS, true)) {
                    $child->remove();

                    continue;
                }

                self::clean($child);

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $child->replaceWith(...iterator_to_array($child->childNodes));

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $child->removeAttribute($attribute->name);
                }
            } elseif (! $child instanceof Text) {
                $child->remove();
            }
        }
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
