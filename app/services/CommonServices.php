<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/10/30 0030
 * Time: 13:34
 */

namespace app\services;


use app\model\Goods as GoodsModel;
use app\model\Image as ImageModel;
use app\model\Order as OrderModel;
use app\model\PtOrder as PtOrderModel;
use app\model\SysConfig as SysConfigModel;
use exceptions\TokenException;
use kuaidi\Kd;
use think\facade\Cache;
use think\facade\Db;
use think\Image;

class CommonServices
{

    /**
     * 导出数据
     * @return int
     */
    public function export_excl()
    {
        $token = input('get.token');
        if (!$token) {
            throw new TokenException();
        }
        $vars = Cache::get($token);
        if (!$vars) {
            throw new TokenException();
        } else {
            if (!is_array($vars)) {
                $vars = json_decode($vars, true);
            }
            if (!array_key_exists('admin_id', $vars)) {
                throw new TokenException(['msg' => '尝试获取的变量并不存在']);
            }
        }
        $list = OrderModel::with(['ordergoods', 'users'])->order('create_time desc')->select()->toArray();
        if (count($list) < 1) {
            return 0;
        }
        $arr = [];
        foreach ($list as $k => $v) {
            $arr[$k]['xu'] = $k + 1;
            $arr[$k]['number'] = $v['order_num'];
            $arr[$k]['status'] = $v['shipment_state'] ? '已发货' : '待发货';
            $pro = '';
            foreach ($v['ordergoods'] as $kk => $vv) {
                $pro .= $vv['goods_name'] . '[x' . $vv['num'] . ']@@';
            }
            $arr[$k]['goods'] = $pro;
            $arr[$k]['goods_money'] = $v['goods_money'];
            $arr[$k]['order_money'] = $v['order_money'];
            $arr[$k]['nickname'] = $v['users']['nickname'];
            $arr[$k]['username'] = $v['receiver_name'];
            $arr[$k]['mobile'] = $v['receiver_mobile'];
            $arr[$k]['city'] = $v['receiver_city'];
            $arr[$k]['address'] = $v['receiver_address'];
            $arr[$k]['create_time'] = $v['create_time'];
        }
        if (app('system')->getValue('is_pt') == 1) {
            $ptList = PtOrderModel::with(['user'])->order('create_time desc')->select()->toArray();
            foreach ($ptList as $v) {
                $k = count($arr);
                $arr[$k]['xu'] = $k + 1;
                $arr[$k]['number'] = $v['order_num'];
                $arr[$k]['status'] = $v['shipment_state'] ? '已发货' : '待发货';
                $arr[$k]['goods'] = $v['goods_name'] . '[x' . $v['num'] . ']@@';
                $arr[$k]['goods_money'] = $v['goods_money'];
                $arr[$k]['order_money'] = $v['order_money'];
                $arr[$k]['nickname'] = $v['user']['nickname'];
                $arr[$k]['username'] = $v['receiver_name'];
                $arr[$k]['mobile'] = $v['receiver_mobile'];
                $arr[$k]['city'] = $v['receiver_city'];
                $arr[$k]['address'] = $v['receiver_address'];
                $arr[$k]['create_time'] = $v['create_time'];
            }
        }
        $csv_title = array('序号', '编号', '状态', '商品名称', '商品总价', '订单总价', '用户昵称', '收货姓名', '收货人手机',
            '收货人城市', '收货人地址', '创建时间');
        $this->put_csv($arr, $csv_title);
    }

    /**
     * 更新不同模型的布尔字段
     * @param $id
     * @param $db
     * @param $field
     * @return int
     */
    public static function upValue($id, $db, $field)
    {
        switch ($db) {
            case 'order':
                $where['order_id'] = $id;
                break;
            case 'goods':
                $where['goods_id'] = $id;
                break;
            case 'category':
                $where['category_id'] = $id;
                break;
            case 'user':
                $where['id'] = $id;
                break;
            case 'article':
                $where['id'] = $id;
                break;
            case 'admins':
                $where['id'] = $id;
                break;
            default:
                return app('json')->fail('找不到模型');
        }
        $vs = Db::name($db)->where($where)->value($field);
        if ($vs == 0) {
            $res = Db::name($db)->where($where)->update([$field => 1]);
        } else {
            $res = Db::name($db)->where($where)->update([$field => 0]);
        }
        if ($res) {
            if ($db == 'goods') {
                GoodsModel::deleteGoods($id);
            }
            return app('json')->success();
        } else {
            return app('json')->fail();
        }
    }

    /**
     * 上传图片
     * @param string $use
     * @param string $back
     * @return mixed
     */
    public static function uploadImg($use, $back, $type = '', $cid = '')
    {
        $file = Image::open(request()->file('img'));
        if (!$file) {
            return app('json')->fail('请上传文件img');
        }
        $name = uniqid() . '.png';
        $file->thumb(500,500,1)->save('./uploads/' . $use . '/' . $name);
        $res = self::img_save($use, $name, $cid);   //保存图片
        if ($res['id']) {
            if ($back == 'id' && $type == 1) {
                return $res['id'];
            } else if ($back == 'id') {
                return app('json')->success($res['id']);
            } else if ($back == 'idurl') {
                $web_url = config('setting.web_url');
                $pic = $web_url . '/uploads/' . $use . '/' . $name;
                $r['id'] = $res->id;
                $r['url'] = $pic;
                return app('json')->success($r);
            } else {
                $web_url = config('setting.web_url');
                $pic = $web_url . '/uploads/' . $use . '/' . $name;
                return app('json')->success([$pic]);
            }
        } else {
            return app('json')->fail();
        }
    }

    /**
     * 上传的图片信息，录入数据库
     * @param $name
     * @param $use
     * @param $cid
     * @return \think\Model|static
     */
    private static function img_save($use, $name, $cid = '')
    {
        $data['use_name'] = $use;
        $data['url'] = '/uploads/' . $use . '/' . $name;
        $data['category_id'] = $cid;
        $res = ImageModel::create($data);
        return $res;
    }

    public static function downImg($url)
    {
        $res = file_get_contents($url);
        $name = uniqid() . '.png';
        $out = fopen('./uploads/product/' . $name, "a");
        fwrite($out, $res);
        fclose($out);
        $img = self::img_save('product', $name);   //保存图片
        return $img['id'];
    }

    /**
     * 快递查询
     * @param $id
     * @return mixed
     */
    public static function getCourier($id)
    {
        $order = OrderModel::where(['order_id' => $id])->field('order_id,courier,courier_num')->find();
        if (!$order['courier'] || !$order['courier_num']) {
            return app('json')->fail('未找到单号');
        }
        $code = SysConfigModel::where(['key' => 'appcode'])->value('value');
        $kd = new Kd($code, $order['courier'], $order['courier_num']);
        return $kd->get();
    }


    /**
     * 快递查询
     * @param $id
     * @return mixed
     */
    public static function getPtCourier($id)
    {
        $order = PtOrderModel::where(['order_id' => $id])->field('order_id,courier,courier_num')->find();
        if (!$order['courier'] || !$order['courier_num']) {
            return app('json')->fail('未找到单号');
        }
        $code = SysConfigModel::where(['key' => 'appcode'])->value('value');
        $kd = new Kd($code, $order['courier'], $order['courier_num']);
        return $kd->get();
    }

    //导出csv文件
    public function put_csv($list, $title)
    {
        $file_name = "CSV" . date("mdHis", time()) . ".csv";
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename=' . $file_name);
        header('Cache-Control: max-age=0');
        $file = fopen('php://output', "a");
        $limit = 1000;
        $calc = 0;
        foreach ($title as $v) {
            $tit[] = iconv('UTF-8', 'GB2312//IGNORE', $v);
        }
        fputcsv($file, $tit);
        foreach ($list as $v) {
            $calc++;
            if ($limit == $calc) {
                ob_flush();
                flush();
                $calc = 0;
            }
            foreach ($v as $t) {
                $tarr[] = iconv('UTF-8', 'GB2312//IGNORE', $t);
            }
            fputcsv($file, $tarr);
            unset($tarr);
        }
        unset($list);
        fclose($file);
        exit();
    }
}