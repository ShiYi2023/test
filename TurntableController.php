<?php

namespace App\Http\Controllers;

use App\Http\Validations\TurntableValidation;
use App\Models\TurntableRecord;
use App\Packer\TurntablePacker;
use App\Services\Turntable\Dao\TurntableUserDao;
use App\Services\Turntable\Turntable;
use App\Services\Turntable\TurntableService;
use App\Services\Turntable\TurntableSystem;
use App\Sy\Config\GiftConfigExtras;
use App\Sy\Const\CodeParam;
use App\Sy\InnerSvc\ConsumeSvc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Sy\Const\RaffleConst;

class TurntableController extends Controller
{
    private TurntableValidation $validation;

    private ConsumeSvc $consumeSvc;

    private TurntableService $turntableService;

    private TurntablePacker $turntablePacker;

    public function __construct(
        Request $request,
        ConsumeSvc $consumeSvc,
        TurntableValidation $validation,
        TurntableService $turntableService,
        TurntablePacker $turntablePacker,
    )
    {
        $this->consumeSvc = $consumeSvc;

        $this->validation = $validation;

        $this->turntableService = $turntableService;

        $this->turntablePacker = $turntablePacker;

        parent::__construct($request);
    }

    // 初始化信息
    public function init()
    {
        $token = request()->header('token');
        $userId = $this->getUid();
        if (!$userId) {
            return $this->errorJson('未登录', CodeParam::UNAUTHORIZED);
        }

        // 宝箱数据
        $turntables = [];
        // 所有宝箱礼物ID
        $giftIdMap = [];

        $sortedBoxes = [];
        foreach (TurntableSystem::getInstance()->boxMap as $turntableId => $box) {
            $sortedBoxes[] = $box;
        }
        usort($sortedBoxes, function ($a, $b) {
            if ($a->price < $b->price) {
                return -1;
            } else if ($a->price > $b->price) {
                return 1;
            }
            return 0;
        });
        foreach ($sortedBoxes as $box) {
            $turntables[] = $this->turntablePacker->encodeBox($box);
            foreach ($box->rewardPoolMap as $_ => $rewardPool) {
                foreach ($rewardPool->giftMap as $giftId => $_) {
                    $giftIdMap[$giftId] = 1;
                }

                $runningRewardPool = $this->turntableService->loadRunningRewardPool($box->turntableId, $rewardPool->poolId);
                if ($runningRewardPool) {
                    foreach ($runningRewardPool->giftMap as $giftId => $_) {
                        $giftIdMap[$giftId] = 1;
                    }
                }
            }
        }

        $giftMap = [];
        foreach ($giftIdMap as $giftId => $_) {
            $giftMap[] = $this->turntablePacker->encodeGift($giftId);
        }

        $ret = [
            'goldNum' => $this->consumeSvc->getUidCoinTotal($token),
            'gifts' => $giftMap,
            'turntables' => $turntables
        ];

        return $this->successJson($ret);
    }

    /**
     * 转盘
     */
    public function turnTable()
    {
        $this->validation->turnTable();
        $userId = $this->getUid();
        $token = request()->header('token');
        if (!$userId) {
            return $this->errorJson('未登录', CodeParam::UNAUTHORIZED);
        }

        $turntableId = intval($this->request->input('turntableId'));
        if ($turntableId == 1) {
           return $this->errorJson('正在更新中~');
        }
        // if ($turntableId == 2 && $userId != '15492225') {
        //     return $this->errorJson('正在更新中~');
        // }
        $count = intval($this->request->input('count'));
        $roomId = intval($this->request->input('roomId'));
        try {
            list($totalPrice, $balance, $giftMap) = $this->turntableService->turnTable($userId, $roomId, $turntableId, $count, $token);
        } catch (\Exception $e) {
            Log::error('turnTable error errorMessage:' . $e->getMessage());
            return $this->errorJson($e->getMessage());
        }

        $gifts = $this->turntablePacker->sortGifts($giftMap);
        $giftList = [];
        $totalPrice = 0;
        foreach ($gifts as $gift) {
            $giftInfo = [];
            $giftInfo['gift_id'] = $gift['id'];
            $giftInfo['num'] = $gift['count'];
            $giftInfo['gift_name'] = GiftConfigExtras::$propConfig[$gift['id']]['title'];
            $giftInfo['price'] = GiftConfigExtras::$propConfig[$gift['id']]['price'];
            $giftInfo['gift_pic'] = GiftConfigExtras::$propConfig[$gift['id']]['pic'];
            $totalPrice += ($giftInfo['price'] * $giftInfo['num']);
            $giftList[] = $giftInfo;
        }

        //记录转盘总消耗，和总产出
        $this->turntableService->setTurnCoin($turntableId, $count, $totalPrice);

        $ret = [
            'totalPrice' => $totalPrice,
            'goldNum' => $this->consumeSvc->getUidCoinTotal($token),
            'rewards' => [
                'gifts' => $giftList,
            ]
        ];

        return $this->successJson($ret);
    }

    // public function testbf()
    // {
    //     // $userId = $this->getUid();
    //     // if($userId == '15353723'){

    //     // }
    //     $results = DB::table('turntable_record')
    //     ->whereRaw("JSON_EXTRACT(result, '$.\"339\"') IS NOT NULL")
    //     ->orWhereRaw("JSON_EXTRACT(result, '$.\"450\"') IS NOT NULL")
    //     ->get();
    //     // print_r($results->toArray());die;
    //     $token = request()->header('token');
    //     foreach ($results->toArray() as $info) {
    //         $gifts = json_decode($info->result, true);

    //         if (!empty($gifts[339])) {
    //             $giftMap = ['339' => $gifts[339]];
    //             // 发放礼物
    //             $responseSetSpecies = $this->consumeSvc->innerAddProps(
    //                 RaffleConst::ObtainID2,
    //                 $info->user_id,
    //                 $token,
    //                 json_encode($giftMap),
    //                 $info->room_id,
    //                 $gifts[339],
    //                 2
    //             );

    //         }

    //         if (!empty($gifts[450])) {
    //             $giftMap = ['450' => $gifts[450]];
    //             // 发放礼物
    //             $responseSetSpecies = $this->consumeSvc->innerAddProps(
    //                 RaffleConst::ObtainID2,
    //                 $info->user_id,
    //                 $token,
    //                 json_encode($giftMap),
    //                 $info->room_id,
    //                 $gifts[450],
    //                 2
    //             );
    //         }
    //     }

    //     echo '补发成功';
    // }


    //砸蛋榜单
    public function rankList()
    {
        $userId = $this->getUid();
        if (!$userId) {
            return $this->errorJson('未登录', CodeParam::UNAUTHORIZED);
        }

        $timestamp = time();
        $fuxinRankList = $this->turntableService->getFuxinRank($timestamp);

        list($rank, $score) = $this->turntableService->getFuxingRankScore($userId, $timestamp);
        $userRank = $rank + 1;

        $ret = [
            'userRank' => $fuxinRankList,
            'my' => [
                'userId' => $userId,
                'rank' => $userRank,
                'score' => $score,
            ]
        ];

        return $this->successJson($ret);
    }

    public function jinliRankList()
    {
        $pageNo = $this->request->input('pageNo', 0);
        $pageSize = $this->request->input('pageSize', 50);
        $turntableId = $this->request->input('turntableId', 0);

        list($total, $rankList) = $this->turntableService->getJinliRankList($turntableId, $pageNo * $pageSize, $pageSize);
        $rankDatas = [];

        if (!empty($rankList)) {
            foreach ($rankList as $rankData) {
                $userId = $rankData['userId'];
                $giftId = $rankData['giftId'];
                $rankDatas[] = [
                    'user_id' => $userId,
                    'gift' => [
                        'gift_id' => $giftId,
                        'gift_name' => GiftConfigExtras::$propConfig[$giftId]['title'],
                        'gift_pic' => GiftConfigExtras::$propConfig[$giftId]['pic'],
                        'count' => $rankData['count'],
                        'time' => $rankData['time']
                    ],
                    'count' => $rankData['count'],
                    'time' => $rankData['time'],
                ];
            }
        }

        $ret = [
            'total' => $total,
            'list' => $rankDatas
        ];

        return $this->successJson($ret);
    }

    // 抽奖记录
    public function record()
    {
        $uid = $this->getUid();
        $page = $this->request->input('page', 1);
        $size = $this->request->input('size', 10);
        $recordList = $this->turntableService->getLastRecordList($uid, $page, $size);

        $result = [];
        foreach ($recordList as $item) {
            $giftList = [];
            $output = json_decode($item['result'], true);
            foreach ($output as $giftId => $num) {
                $giftInfo = [];
                $giftInfo['gift_id'] = $giftId;
                $giftInfo['num'] = $num;
                $giftInfo['gift_name'] = GiftConfigExtras::$propConfig[$giftId]['title'] ?? '';
                $giftInfo['gift_pic'] = GiftConfigExtras::$propConfig[$giftId]['pic'] ?? '';
                $giftList[] = $giftInfo;
            }
            $res = [];
            $res['id'] = $item['id'];
            $res['info'] = $giftList;
            $res['create_time'] = $item['create_time'];
            $result[] = $res;
        }
        $ret = [
            'list' => $result,
        ];

        return $this->successJson($ret);
    }

    public function setConf()
    {

        $conf = $this->request->input('conf', '');
        if (empty($conf)) {
            return $this->errorJson('配置错误', 500);
        }
        TurntableSystem::setConf($conf);
        return $this->successJson($conf);
    }

    public function checkBaolv() {
        $conf = $this->request->input('conf', '');
        if (empty($conf)) {
            return $this->errorJson('配置错误', 500);
        }
        $conf = json_decode($conf,true);
        $boxMap = [];
        $turntablesConf = $conf['turntables'];
        foreach ($turntablesConf as $turntableConf) {
            $turntable = new Turntable();
            $turntable->decodeFromJson($turntableConf);
            if (array_key_exists($turntable->turntableId, $boxMap)) {
                Log::warning(sprintf('Turntable::decodeConf DuplicateBox turntableId=%s', $turntable->turntableId));
                throw new \Exception('转盘id配置重复，id=' . $turntable->turntableId, 500);
            }
            $boxMap[$turntable->turntableId] = $turntable;
            foreach ($turntable->rewardPoolMap as $poolId => $rewardPool) {
                list($consume, $reward) = TurntableSystem::calcBaolv($turntable->price, $rewardPool->giftMap);
                $baolv = floatval($reward) / floatval($consume);
                Log::info(sprintf('Turntable::checkBaolv turntableId=%d poolId=%d baolv=%d:%d:%.6f', $turntable->turntableId, $poolId,
                    $reward, $consume, $baolv));
                if ($baolv > TurntableSystem::$maxBaolv) {
                    Log::error(sprintf('Turntable::checkBaolv turntableId=%d poolId=%d baolv=%d:%d:%.6f', $turntable->turntableId, $poolId,
                        $reward, $consume, $baolv));
                    throw new \Exception('爆率配置错误,poolId=' . $poolId, 500);
                }
            }
            TurntableSystem::checkBaolv($turntable);
        }
        return $this->successJson('检测完成');
    }

    public function getAllTurntableRunningPool()
    {
        $turntableId = intval($this->request->input('turntableId'));

        Log::info(sprintf('TurntableController::getAllTurntableRunningPool turntableId=%d', $turntableId));

        $box = TurntableSystem::getInstance()->findBox($turntableId);
        if ($box == null) {
            return $this->errorJson('配置错误', 500);
        }

        $ret = [];

        foreach ($box->rewardPoolMap as $poolId => $rewardPool) {
            $runningRewardPool = $this->turntableService->loadRunningRewardPool($box->turntableId, $poolId);
            $ret[] = $this->turntablePacker->encodeRunningRewardPool($runningRewardPool, $box->price);
        }

        return $this->successJson($ret);
    }


    public function getTurntableUser()
    {
        $turntableId = intval($this->request->input('turntableId'));
        $userId = intval($this->request->input('userId'));

        Log::info(sprintf('TurntableController::getTurntableUser turntableId=%d userId=%d', $turntableId, $userId));

        $box2User = TurntableUserDao::getInstance()->loadBoxUser($userId, $turntableId);

        return $this->successJson($box2User->toJsonWithBaolv());
    }

    public function refreshPool()
    {
        $turntableId = intval($this->request->input('turntableId'));
        $poolId = intval($this->request->input('poolId'));
        $userId = intval($this->request->input('userId'));

        $box = TurntableSystem::getInstance()->findBox($turntableId);
        if ($box == null) {
            return $this->errorJson('配置错误', 500);
        }

        Log::info(sprintf('TurntableController::refreshPool turntableId=%d userId=%d', $turntableId, $userId));

        $runningRewardPool = $this->turntableService->refreshRewardPool($turntableId, $poolId);

        $poolData = $this->turntablePacker->encodeRunningRewardPool($runningRewardPool, $box->price);

        return $this->successJson($poolData);
    }

    public function refreshAllPool()
    {
        $turntableId = intval($this->request->input('turntableId'));
        $userId = intval($this->request->input('userId'));

        Log::info(sprintf('TurntableController::refreshAllPool turntableId=%d userId=%d', $turntableId, $userId));

        $box = TurntableSystem::getInstance()->findBox($turntableId);
        if ($box == null) {
            return $this->errorJson('配置错误', 500);
        }

        $pools = [];
        foreach ($box->rewardPoolMap as $poolId => $rewardPool) {
            $runningRewardPool = $this->turntableService->refreshRewardPool($turntableId, $poolId);
            $pools[] = $this->turntablePacker->encodeRunningRewardPool($runningRewardPool, $box->price);
        }

        return $this->successJson(
            [
                'turntableId' => $turntableId,
                'pools' => $pools
            ]);
    }
}
