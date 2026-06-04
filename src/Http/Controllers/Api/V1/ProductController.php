<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedMobileApi\Http\Resources\ProductResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::thisSite();

        if ($request->has('public')) {
            $query->where('public', $request->boolean('public'));
        }

        if ($search = $request->query('search')) {
            $query->search((string) $search);
        }

        $perPage = (int) config('dashed-mobile-api.default_page_size', 25);

        return ProductResource::collection($query->paginate($perPage));
    }

    public function show(int $product): ProductResource
    {
        return new ProductResource(Product::thisSite()->findOrFail($product));
    }

    public function update(Request $request, int $product): ProductResource
    {
        $model = Product::thisSite()->findOrFail($product);

        $data = $request->validate([
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'public' => ['sometimes', 'boolean'],
        ]);

        $model->fill($data)->save();

        activity()
            ->performedOn($model)
            ->causedBy($request->user())
            ->withProperties($data)
            ->log('mobile-api: product bijgewerkt');

        return new ProductResource($model->fresh());
    }
}
