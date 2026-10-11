<?php

use App\Services\SvgSanitizer;

function sanitizeSvg(string $svg): string
{
    $source = tempnam(sys_get_temp_dir(), 'svg-test');
    file_put_contents($source, $svg);

    try {
        $clean = (new SvgSanitizer)->sanitize($source);
        $contents = (string) file_get_contents($clean);
        @unlink($clean);

        return $contents;
    } finally {
        @unlink($source);
    }
}

it('rejects an svg that declares a doctype or entities', function (string $svg): void {
    expect(fn () => sanitizeSvg($svg))->toThrow(RuntimeException::class);
})->with([
    'entity' => '<!DOCTYPE svg [<!ENTITY x "<script>alert(1)</script>">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
    'billion laughs' => '<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>',
    'attlist' => '<!DOCTYPE svg [<!ATTLIST svg onload CDATA "alert(1)">]><svg xmlns="http://www.w3.org/2000/svg"/>',
]);

it('removes an animation that rewrites a link to javascript', function (): void {
    $clean = sanitizeSvg(<<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg"><a href="#x"><animate attributeName="href" to="javascript:alert(1)"/><circle r="4"/></a></svg>
SVG);

    expect($clean)->not->toContain('animate')
        ->and($clean)->not->toContain('javascript')
        ->and($clean)->toContain('<circle');
});

it('drops script schemes hidden with whitespace', function (): void {
    $clean = sanitizeSvg("<svg xmlns=\"http://www.w3.org/2000/svg\"><animate attributeName=\"fill\" values=\"java\tscript:alert(1)\"/><circle r=\"4\"/></svg>");

    expect($clean)->not->toContain('script:')
        ->and($clean)->toContain('<circle');
});

it('removes unsafe css and keeps plain styles', function (): void {
    $clean = sanitizeSvg(<<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.test/x.css);</style><style>.a{fill:red}</style><rect class="a" style="background:url(https://evil.test/t.png)" width="2" height="2"/><circle style="fill:blue" r="4"/></svg>
SVG);

    expect($clean)->not->toContain('evil.test')
        ->and($clean)->toContain('.a{fill:red}')
        ->and($clean)->toContain('fill:blue');
});

it('strips processing instructions and comments inside the drawing', function (): void {
    $clean = sanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg"><?xml-stylesheet href="https://evil.test/x.xsl"?><!-- note --><circle r="4"/></svg>');

    expect($clean)->not->toContain('xml-stylesheet')
        ->and($clean)->not->toContain('note')
        ->and($clean)->toContain('<circle');
});
