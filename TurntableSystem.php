<?php

namespace App\Services\Turntable;


use App\Sy\Config\GiftConfigExtras;
use App\Sy\Const\TurntableConst;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TurntableSystem
{
    protected static $instance;
    public static $maxBaolv = 1.20;
    public $isOpen = 1;
    public $defaultCounts = [1, 10, 66];
    public $customCountRange = [5, 200];
    // map<turntableId, Turntable>
    public $boxMap = null;

    // 全服公屏消息最小礼物价值
    public $fullPublicGiftValue = 0;
    // 房间飘屏最小礼物价值
    public $fullFlutterGiftValue = 0;

    //单例
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new TurntableSystem();
            self::$instance->loadFromJson();
        }
        return self::$instance;
    }

    public function findBox($turntableId)
    {
        return $this->boxMap[$turntableId] ?? null;
    }

    public static function calcBaolv($price, $giftMap)
    {
        $consume = 0;
        $reward = 0;
        foreach ($giftMap as $giftId => $count) {
            $consume += $count * $price;
            $giftKind = GiftConfigExtras::$propConfig[$giftId] ?? null;
            if ($giftKind != null) {
                $reward += $giftKind['price'] * $count;
            }
        }
        return [
            $consume, $reward
        ];
    }

    public static function setConf($conf)
    {
        $newConf = json_decode($conf,true);
        self::decodeConf($newConf);
        Redis::set(TurntableConst::TurntableConfig, $conf);
    }

    public static function decodeConf($conf)
    {
        $boxMap = [];
        $turntablesConf = $conf['turntables'];
        foreach ($turntablesConf as $turntableConf) {
            $turntable = new Turntable();
            $turntable->decodeFromJson($turntableConf);
            if (array_key_exists($turntable->turntableId, $boxMap)) {
                Log::warning(sprintf('TurntableSystem::decodeConf DuplicateBox turntableId=%s', $turntable->turntableId));
                throw new \Exception('转盘id配置重复，id=' . $turntable->turntableId, 500);
            }
            $boxMap[$turntable->turntableId] = $turntable;
            self::checkBaolv($turntable);
        }

        $countConf = $conf['count'];
        if (empty($countConf)) {
            Log::error(sprintf('TurntableSystem::decodeConf NotFoundCount'));
            throw new \Exception('价格次数配置错误', 500);
        }

        $defaultCountList = $countConf['default'];
        foreach ($defaultCountList as $defaultCount) {
            if (!is_int($defaultCount)) {
                Log::error(sprintf('TurntableSystem::decodeConf BadDefaultCount default=%s', json_encode($defaultCountList)));
                throw new \Exception('价格次数配置错误', 500);
            }
        }

        $customCountRange = $countConf['custom'];
        if (count($customCountRange) != 2) {
            Log::error(sprintf('TurntableSystem::decodeConf BadCustomCountLen custom=%s', json_encode($customCountRange)));
            throw new \Exception('价格次数配置错误', 500);
        }

        foreach ($customCountRange as $customCount) {
            if (!is_int($customCount)) {
                Log::error(sprintf('TurntableSystem::decodeConf BadCustomCountValue custom=%s', json_encode($defaultCountList)));
                throw new \Exception('价格次数配置错误', 500);
            }
        }

        $fullPublicGiftValue = max($conf['fullPublicGiftValue'], 500);
        $fullFlutterGiftValue = max($conf['fullFlutterGiftValue'], 500);

        $isOpen = $conf['isOpen'] ?? 1;

        return [
            'boxMap' => $boxMap,
            'isOpen' => $isOpen,
            'count' => $countConf,
            'fullPublicGiftValue' => $fullPublicGiftValue,
            'fullFlutterGiftValue' => $fullFlutterGiftValue
        ];
    }

    public static function checkBaolv($box)
    {
        foreach ($box->rewardPoolMap as $poolId => $rewardPool) {
            list($consume, $reward) = self::calcBaolv($box->price, $rewardPool->giftMap);
            $baolv = floatval($reward) / floatval($consume);
            Log::info(sprintf('TurntableSystem::checkBaolv turntableId=%d poolId=%d baolv=%d:%d:%.6f', $box->turntableId, $poolId,
                $reward, $consume, $baolv));
            if ($baolv > self::$maxBaolv) {
                Log::error(sprintf('TurntableSystem::checkBaolv turntableId=%d poolId=%d baolv=%d:%d:%.6f', $box->turntableId, $poolId,
                    $reward, $consume, $baolv));
                throw new \Exception('爆率配置错误,poolId=' . $poolId, 500);
            }
        }
    }

    private function loadFromJson()
    {
        $conf = Redis::get(TurntableConst::TurntableConfig);
        $conf = json_decode($conf,true);
        $decodedConf = $this->decodeConf($conf);

        $this->isOpen = $decodedConf['isOpen'];
        $this->boxMap = $decodedConf['boxMap'];
        $this->fullPublicGiftValue = $decodedConf['fullPublicGiftValue'];
        $this->fullFlutterGiftValue = $decodedConf['fullFlutterGiftValue'];

        Log::info(sprintf('TurntableSystem::loadFromJson ok isOpen=%d fullPublicGiftValue=%d fullFlutterGiftValue=%d turntableIds=%s',
            $this->isOpen, $this->fullPublicGiftValue, $this->fullFlutterGiftValue, json_encode(array_keys($this->boxMap))));
    }
}
