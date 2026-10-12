<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoFileGenerator;
use App\Services\Seo\SeoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public SEO read endpoints the Next.js BFF calls while rendering.
 */
class SeoController extends Controller
{
    public function page(Request $request, SeoResolver $resolver): JsonResponse
    {
        $request->validate(['path' => ['nullable', 'string', 'max:2048']]);

        return $this->apiSuccess('Page SEO.', [
            'seo' => $resolver->forPath((string) $request->query('path', '/')),
        ]);
    }

    public function file(string $file, SeoFileGenerator $generator): Response
    {
        $contents = $generator->read($file);

        if ($contents === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => str_ends_with($file, '.xml') ? 'application/xml; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
