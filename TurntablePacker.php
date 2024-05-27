<?php


namespace App\Packer;

use App\Services\Turntable\PoolTypes;
use App\Services\Turntable\TurntableSystem;
use App\Sy\Config\GiftConfigExtras;
use App\Sy\Helper\Helper;

class TurntablePacker
{
    public function encodeGift($giftId)
    {
        $giftInfo = GiftConfigExtras::$propConfig[$giftId] ?? [];

        return [
            'gift_id' => $giftId,
            'gift_pic' => $giftInfo['pic'] ?? '',
            'gift_name' => $giftInfo['title'] ?? '',
            'price' => $giftInfo['price'] ?? 0,
        ];
    }

    public function encodeBox($box)
    {
        // 取最后一个奖池类型的第一个奖池
        $rewardPool = null;
        $typedPool = $box->findTypedPool(PoolTypes::$PUBLIC_ONE); //尝试从传入的$box对象中找到类型为PoolTypes::$PUBLIC_ONE的奖池。
        if (!empty($typedPool)) {
            $rewardPool = $typedPool->rewardPools[0]; //如果找到，则获取该类型奖池的第一个奖励池$rewardPool
        }
        return [ //返回一个数组，包含“箱子”的turntableId、name、price，以及（如果存在的）经过encodeGiftBaolv函数处理的奖励池礼物信息。
            'turntableId' => $box->turntableId,
            'name' => $box->name,
            'price' => $box->price,
            'gifts' => $rewardPool != null ? $this->encodeGiftBaolv($rewardPool) : []
        ];
    }

    public function encodeGiftBaolv($rewardPool) //[[gift_id=>weight]]
    {
        $ret = [];
        foreach ($rewardPool->giftMap as $giftId => $weight) { //遍历传入的$rewardPool对象中的giftMap，这是一个映射礼物ID到权重的结构。
            $giftKind = GiftConfigExtras::$propConfig[$giftId] ?? null; //对于每个礼物ID，尝试从GiftConfigExtras::$propConfig中获取礼物的详细信息
            if ($giftKind) { //如果礼物信息存在，则计算其“表外概率”（这里直接使用了extTableBaoLv函数返回的固定值进行四舍五入），并构建一个包含礼物详细信息和权重的数组。
                // $propb = (float)$weight / $rewardPool->totalWeight * 10000;
                $ret[] = [
                    'giftId' => $giftId,
                    'giftName' => $giftKind['title'],
                    'pic' => $giftKind['pic'],
                    'price' => $giftKind['price'],
                    'weight' => round($this->extTableBaoLv()[$giftId], 2)
                ];
            }
        }
        return $ret;
    }

    /**
     * 表外概率
     */
    public function extTableBaoLv(){
        $devArr = [
            '3' => 3885,
            '7' => 2775,
            '16' => 1790,
            '22' => 610,
            '36' => 495,
            '66' => 275,
            '85' => 110,
            '94' => 45,

            '164' => 4333,
            '165' => 2099,
            '166' => 1888,
            '167' => 1234,
            '168' => 227,
            '169' => 111,
            '170' => 88,
            '171' => 6,
            '172' => 5,
            '173' => 4,
            '174' => 3,
            '175' => 2,
        ];

        $prodArr = [
            '3' => 3885,
            '7' => 2775,
            '16' => 1790,
            '22' => 610,
            '36' => 495,
            '66' => 275,
            '85' => 110,
            '94' => 45,

            '164' => 4333,
            '165' => 2099,
            '166' => 1888,
            '167' => 1234,
            '168' => 227,
            '169' => 111,
            '170' => 88,
            '171' => 6,
            '172' => 5,
            '173' => 4,
            '174' => 3,
            '175' => 2,
        ];

        return Helper::isDev() ? $devArr : $prodArr;
    }

    public function sortGifts($giftMap)
    {
        # 价值最大的放数组末尾
        $maxPrice = 0;
        $maxPriceGiftId = 0;
        foreach ($giftMap as $giftId => $count) {
            $giftKind = GiftConfigExtras::$propConfig[$giftId] ?? null;
            $price = $giftKind['price'] ?? 0;
            if ($price > $maxPrice) {
                $maxPrice = $price;
                $maxPriceGiftId = $giftId;
            }
        }

        $gifts = [];
        foreach ($giftMap as $giftId => $count) {
            if ($giftId == $maxPriceGiftId) {
                continue;
            }
            $gifts[] = [
                'id' => $giftId,
                'count' => $count
            ];
        }

        if ($maxPriceGiftId > 0) {
            $gifts[] = [
                'id' => $maxPriceGiftId,
                'count' => $giftMap[$maxPriceGiftId]
            ];
        }

        return $gifts;
    }

    public function encodeRunningRewardPool($runningRewardPool, $price)
    {
        $gifts = [];
        foreach ($runningRewardPool->giftMap as $giftId => $count) {
            $giftKind = GiftConfigExtras::$propConfig[$giftId] ?? null;
            $gifts[] = [
                'giftId' => $giftId,
                'count' => $count,
                'giftName' => $giftKind['title'],
                'pic' => $giftKind['pic'],
                'price' => $giftKind['price'],
            ];
        }
        $baolv = TurntableSystem::calcBaolv($price, $runningRewardPool->giftMap);
        return [
            'poolId' => $runningRewardPool->poolId,
            'gifts' => $gifts,
            'baolv' => [
                'consume' => $baolv[0],
                'reward' => $baolv[1],
                'baolv' => round(floatval($baolv[1]) / floatval($baolv[0]), 6)
            ]
        ];
    }
}
