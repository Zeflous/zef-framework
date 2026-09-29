<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: the single seam through which every scaffold template is
 * rendered before it reaches ScaffoldWriter.
 *
 * TODAY THIS IS AN IDENTITY FUNCTION. Every generator still embeds its
 * template as a heredoc and hands the rendered string to this class, which
 * returns it unchanged — so the bytes written to disk are exactly the bytes
 * the generator produced before this seam existed.
 *
 * WHY THE SEAM EXISTS. Template CONTENT and template DELIVERY are two
 * separate concerns, and today they are fused: the template lives inside the
 * generator class, so changing a scaffold means editing generator logic.
 * Routing every template through one named seam makes the delivery mechanism
 * replaceable (external files, a cache, a different renderer) without
 * touching a single generator, and gives that future change one testable
 * place to land.
 *
 * CONTRACT. render() is pure: same input, same output, no IO, no state. It
 * must never throw for a well-formed template and must never rewrite the
 * template's bytes. Any future implementation that breaks byte-fidelity is a
 * behaviour change and needs its own migration.
 */

namespace Zef\Framework\Console;

final readonly class TemplateLoader
{
    /**
     * Render a scaffold template into the exact bytes to be written.
     *
     * @param string $template the template body, already interpolated by the caller
     *
     * @return string the bytes to write — identical to $template today
     */
    public function render(string $template): string
    {
        return $template;
    }
}
