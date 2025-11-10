<?php
declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Order\StoreRequest;
use Illuminate\Validation\Rule; 
use Log;
use ReflectionClass;

class PaymentRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $userId         = auth('sanctum')->id();

        $cartId         = request('cart_id');
        $parcelId       = request('parcel_id');
        $subscriptionId = request('subscription_id');
        $adsPackageId   = request('ads_package_id');
        $walletId       = request('wallet_id');

        $rules = [];

        if ($cartId) {
            $rules = (new StoreRequest)->rules();
        }

        $reflectionClass = new ReflectionClass('Iyzipay\Model\PaymentChannel');
        $constants = $reflectionClass->getConstants();

        if (!request('holder_name')) {
            Log::error('iyzico', request()->all());
        }

        return [
            'cart_id' => [
                !$adsPackageId && !$parcelId && !$subscriptionId && !$walletId ? 'required' : 'nullable',
                Rule::exists('carts', 'id')->where('owner_id', $userId)
            ],
            'parcel_id' => [
                !$cartId && !$adsPackageId && !$subscriptionId && !$walletId ? 'required' : 'nullable',
                Rule::exists('parcel_orders', 'id')->where('user_id', $userId)
            ],
            'subscription_id' => [
                !$cartId && !$adsPackageId && !$parcelId && !$walletId ? 'required' : 'nullable',
                Rule::exists('subscriptions', 'id')->where('active', true)
            ],
            'ads_package_id' => [
                !$cartId && !$parcelId && !$subscriptionId && !$walletId ? 'required' : 'nullable',
                Rule::exists('ads_packages', 'id')
                    ->where('active', true)
            ],
            'wallet_id' => [
                !$cartId && !$adsPackageId && !$parcelId && !$subscriptionId ? 'required' : 'nullable',
                Rule::exists('wallets', 'id')->where('user_id', auth('sanctum')->id())
            ],
            'total_price' => [
                !$cartId && !$adsPackageId && !$parcelId && !$subscriptionId ? 'required' : 'nullable',
                'numeric'
            ],
            'holder_name'  => 'string|min:5|max:255',
            'card_number'  => 'numeric',
            'expire_month' => 'numeric|max:12',
            'expire_year'  => 'int',
            'cvc' 		   => 'string|max:255',
            'chanel' 	   => 'string|in:' . implode(',', $constants),
        ] + $rules;
    }

}
