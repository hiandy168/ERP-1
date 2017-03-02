<?php

/**
 * 发货单打印（new）
 */

define('IN_ECS', true);
require_once('includes/init.php');
include_once 'function.php';
require_once('includes/lib_order.php');
// include_once('includes/lib_order_mixed_status.php');
require_once(ROOT_PATH . 'RomeoApi/lib_inventory.php');
require_once(ROOT_PATH . 'RomeoApi/lib_facility.php');
if(isset($_REQUEST['shipment_id']) && is_string($_REQUEST['shipment_id'])){
    $shipment_id = preg_split('/\s*,\s*/',$_REQUEST['shipment_id'],-1,PREG_SPLIT_NO_EMPTY);

}
else {
    die("参数错误");
}
$flag = 0;
 
//独立发货单的店铺   亨氏唯品会，惠氏微商城，惠氏金宝宝
$distributor_page = array(2386,2071,2333);
//二维码店铺   亨氏官方旗舰店，亨氏食品旗舰店，huggies好奇旗舰店
$distributor_2D = array(1953,2531,317);
$distributor_2D_header=array(
    1953=>'',
    2531=>'',
    317=>'扫码收货，好评即送5元优惠券',
);
$distributor_2D_footer=array(
    1953=>'亨氏官方旗舰店',
    2531=>'亨氏食品旗舰店',
    317=>'',
);
$distributor_2D_size=array(
    1953=>85,
    2531=>120,
    317=>120,
);


/**
 * 店铺logo：
 * 好奇聚美商城，顺丰优选-好奇，亨氏宝宝树，康贝京东，康贝苏宁，美囤妈妈,玛氏京东旗舰店
 * 百度mall（康贝，玛氏，好奇，舒洁，亨氏，惠氏，雀巢，妮维雅，高洁丝）
 */
$distributor_logo = array(2657,2656,2655,2654,2653,2652,2650,2648,2649,2646,2522,2563,2604,2577,2600,2672,2000);

// 查询shipment
$sql = "
    select 
        bpm.batch_pick_sn as BPSN,
        bpm.grid_id,if(LOCATE('刻字',oa.attr_value)!=0,'Y','N') BB_sale,
        if(LOCATE('暗号',oa.attr_value)!=0 or LOCATE('OFFERCODE',UPPER(replace(oa.attr_value,' ','')))!=0,'Y','N') LM_sale,
        if(LOCATE('First500',oa.attr_value)!=0,'Y','N') SH_sale,
        o.order_id,o.order_sn,o.consignee,o.order_time,o.taobao_order_sn,o.shipping_id,o.email,
        o.order_amount,o.shipping_fee,o.goods_amount,o.bonus,o.inv_payee, o.pay_name,
        o.country,o.province,o.city,o.district,o.address,o.tel,o.mobile,o.party_id,o.facility_id,
        cast((o.goods_amount+o.bonus) as decimal(10,2)) as order_amount,
        IF( o.order_type_id = 'SALE', 
            (SELECT action_time FROM ecshop.ecs_order_action WHERE order_id = o.order_id AND order_status = 1 LIMIT 1), 
            o.order_time ) AS confirm_time,
        s.SHIPMENT_ID,s.SHIPMENT_TYPE_ID,s.CARRIER_ID,s.PARTY_ID, p.pay_code, o.zipcode,
        o.distributor_id
    from
        romeo.shipment s
        left join romeo.order_shipment os on os.SHIPMENT_ID=s.SHIPMENT_ID
        left join ecshop.ecs_order_info as o on o.order_id=os.ORDER_ID 
        left join ecshop.ecs_payment p on o.pay_id = p.pay_id
        left join romeo.batch_pick_mapping bpm on bpm.shipment_id = s.SHIPMENT_ID 
        left join ecshop.order_attribute oa on oa.order_id = o.order_id and attr_name='TAOBAO_SELLER_MEMO'
    where s.shipping_category = 'SHIPPING_SEND' and 
        s.SHIPMENT_ID ". db_create_in($shipment_id);
//Qlog::log('no order_id check SQL:'.$sql);
$ref_fields=$ref_rowset=array();
$result=$db->getAllRefby($sql,array('grid_id','SHIPMENT_ID','order_id','facility_id','party_id'),$ref_fields,$ref_rowset);

$shipment_important_note = '';// shipment级别的重要备注
$taobaoOrderSnStr = '';
if($result)
{
    $smarty->assign('BPSN', $result[0]['BPSN']);
    // 同一个仓库
    $facility_id=reset($ref_fields['facility_id']);
    $party_id=reset($ref_fields['party_id']);

    // 取得订单的商品明细
    $sql2="
        select
            og.order_id,-- if(og.group_code is not null and og.group_code!='',og.group_code,'单品') as group_code,if(og.group_name is not null and og.group_name !='',og.group_name,'') as group_name,if(og.group_number is null or og.group_number=0 or og.group_number ='','',og.group_number) as group_number,
            og.goods_name,og.goods_number,FORMAT(og.goods_price,2) as goods_price,og.goods_id,og.style_id,g.uniq_sku,
            avg(og.goods_price), sum(og.goods_number) as goods_number,
            sum(og.goods_price * og.goods_number) as goods_amount, og.rec_id, ifnull(g.barcode, '') as barcode, ifnull(sg.barcode, '') as style_barcode,g.cat_id, g.spec
        from
            ecshop.ecs_order_goods og 
            left join ecshop.ecs_goods g on og.goods_id = g.goods_id
            left join ecshop.ecs_goods_style sg on sg.goods_id = g.goods_id and og.style_id = sg.style_id and sg.is_delete=0
        where
            og.order_id ". db_create_in($ref_fields['order_id']) ."
            group by og.order_id,og.goods_id, og.style_id --  ,og.group_code 
            -- order by og.group_code desc  ";
//            Qlog::log('no order_id check SQL:'.$sql2);
    $ref_goods_fields=$ref_goods_rowset=array();
    $result2=$db->getAllRefby($sql2,array('order_id','rec_id'),$ref_goods_fields,$ref_goods_rowset);

 
    // 取得订单的属性
    $sql3="
        select * from ecshop.order_attribute where order_id " . db_create_in($ref_fields['order_id']);
        //Qlog::log('获取订单属性'.$sql3);
    $ref_attribute_fields=$ref_attribute_rowset=array();
    $result3=$db->getAllRefby($sql3,array('order_id'),$ref_attribute_fields,$ref_attribute_rowset);

    
    // 组装数据
    if($result2)
    {
        // 取得订单商品的属性
        $sql4="
            select * from ecshop.order_goods_attribute where order_goods_id ". db_create_in($ref_goods_fields['rec_id']);
        $ref_og_attribute_fields=$ref_og_attribute_rowset=array();
        $result4=$db->getAllRefby($sql4,array('order_goods_id'),$ref_og_attribute_fields,$ref_og_attribute_rowset);
        
        // 组装数据
        foreach($ref_rowset['order_id'] as $order_id=>$order_rowset)
        {
            $order=&$ref_rowset['order_id'][$order_id][0];
            
            // 更新订单的状态为已经打印
            // update_order_mixed_status($order_id, array('pick_list_status'=>'printed'), 'worker');
            
            // 取得订单的商品
            if(isset($ref_goods_rowset['order_id'][$order_id]))
            {
                // 取得商品库位并按库位排序
                $sort=array();
                foreach ($ref_goods_rowset['order_id'][$order_id] as $goods_key => $goods_item)
                {
                    
                    //取得商品库位列表
                    $facility_location_list=facility_location_list_by_product_in_new_wms(getProductId($goods_item['goods_id'],$goods_item['style_id']),$facility_id);
                    $ref_goods_rowset['order_id'][$order_id][$goods_key]['location_seq_id']=$facility_location_list;
                    
                    //取得商品生产日期/批次列表
                    $product_validity_batch_sn_list=get_product_validity_batch_sn(getProductId($goods_item['goods_id'],$goods_item['style_id']),$order_id);
                    $ref_goods_rowset['order_id'][$order_id][$goods_key]['validity_batch_sn']=$product_validity_batch_sn_list;
                        
                    // 取得商品编码
                    if (!empty($goods_item['barcode'])) {
                        $ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code'] = $goods_item['barcode'];
                    }
                    if (!empty($goods_item['style_barcode'])) {
                        $ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code'] = $goods_item['style_barcode'];
                    }
                    if (empty($ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code'])) {
                        $ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code'] = encode_goods_id($goods_item['goods_id'],$goods_item['style_id']);
                    }
                
                    if(65574 == $party_id){
                        $ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code']=$goods_item['style_barcode'] ;                     
                        if(empty($ref_goods_rowset['order_id'][$order_id][$goods_key]['product_code']) && $goods_item['cat_id'] != 2549 && $goods_item['cat_id'] != 2550  ){
                            $flag = 1;
                        }
                    }
                    
                    // 取得商品的属性
                    if(isset($ref_og_attribute_rowset['order_goods_id'][$goods_item['rec_id']]))
                    {
                        foreach($ref_og_attribute_rowset['order_goods_id'][$goods_item['rec_id']] as $og_attribute_key=>$og_attribute_item)
                        {
                            $ref_goods_rowset['order_id'][$order_id][$goods_key]['order_goods_attribute'][$og_attribute_item['name']]=$og_attribute_item['value'];      
                        }
                    }
                }
                // 按库位排序
                if (!empty($sort)) {
                    @array_multisort($sort, SORT_ASC, SORT_STRING, $ref_goods_rowset['order_id'][$order_id]);
                }

                
                $order['goods_list']=$ref_goods_rowset['order_id'][$order_id];
                usort($order['goods_list'], "cmp_wms_location");
            }
            
            // 取得订单的属性
            if(isset($ref_attribute_rowset['order_id'][$order_id]))
            {
                foreach ($ref_attribute_rowset['order_id'][$order_id] as $attribute_key => $attribute_item)
                {
                    $order['order_attribute'][$attribute_item['attr_name']] = $attribute_item['attr_value'];    
                }
            }
            
            // 获取重要备注
            $sql = "
                select action_note from ecs_order_action where order_id = '{$order['order_id']}' and note_type = 'SHIPPING'
            ";
            $important_note = $db->getCol($sql);
            if (is_array($important_note)) {
                $order['important_note'] = join("；", $important_note);
                $shipment_important_note .= $order['important_note'].' ';
            }
        }
    }

    // 取得显示的Shipment列表 
    $list=array();
    $taobaoOrderSnArr=array();
    foreach($ref_rowset['SHIPMENT_ID'] as $shipment_key => $shipment_rowset)
    {
        $reset=reset($shipment_rowset);
        //康贝业务根据店铺（旗舰店、分销）单独设立库位号
        $isKangbei="NO";
        $order_type="";
        if('65586' == $reset['PARTY_ID']){
            $isKangbei="YES";
            $order_type=getOrderTypeZhixiaoFenxiao($reset['SHIPMENT_ID']);
        }   
        
        //拼接得到所有发货单对应订单的淘宝订单号信息
        $taobaoOrderSn="'".$reset['taobao_order_sn']."'";
        array_push($taobaoOrderSnArr, $taobaoOrderSn);

            
        $list[$shipment_key]=array(
            'facilityId'=>$facility_id,
            'gridId'=>$reset['grid_id'],
            'shipmentId'=>$reset['SHIPMENT_ID'],
            'shipmentTypeId'=>$reset['SHIPMENT_TYPE_ID'],
            'carrierId'=>$reset['CARRIER_ID'],
            'partyId'=>$reset['PARTY_ID'],
            'mobile'=>keitai_angouka($reset['mobile']),
            'real_mobile'=>$reset['mobile'],
            'tel'=>keitai_angouka($reset['tel']),
            'province'=>$reset['province'],
            'city'=>$reset['city'],
            'district'=>$reset['district'],
            'address'=>$reset['address'],
            'consignee'=>$reset['consignee'],
            'pay_code' => $reset['pay_code'],
            'zipcode' => $reset['zipcode'],
            'isSuzhouLebeiHuishi'=>"NO",
            //康贝业务根据店铺（旗舰店、分销）单独设立库位号
            'isKangbei'=>$isKangbei,
            'order_type'=>$order_type,
        );
        $list[$shipment_key]['order_list']=$shipment_rowset;
    }
    $taobaoOrderSnStr = (string)implode(',',$taobaoOrderSnArr);
}

$sql ="select distinct distributor_id from ecshop.ecs_order_info oi " .
    " inner join romeo.order_shipment os on cast(os.order_id as unsigned)= oi.order_id " .
    " where os.shipment_id ". db_create_in($shipment_id);
$distributor_id = $db->getCol($sql);
    
$smarty->assign('shipment_important_note', $shipment_important_note);

if($flag){
    alert_back('请联系店长，维护SKU编码', 'shipment_list') ;
}

$smarty->assign('facility_id', $facility_id);
//按grid_id排序
usort($list, "sort_list_by_grid_id");

$greetingsArr=getGreetingsByTaobaoOrderSn($taobaoOrderSnStr);

//订单类型需要添加特殊标识的处理(一个批次中可包含)  + 好奇众筹（来源于好奇-微信） + 苏州乐贝母婴专营
foreach ($list as $paper_id => $paper) {
    if($paper['order_list']){
        $list[$paper_id]['Shop2DBarcode']="NO";
        $list[$paper_id]['ShopLogo']="NO";
        $list[$paper_id]['isSuzhouLebeiHuishi']="NO";
        $list[$paper_id]['isHaoQiZhongChou']="NO";
        foreach ($paper['order_list'] as $ol_id => $one_order) {
            $taobaoOrderSn=$one_order['taobao_order_sn'];
            $pos = strpos($taobaoOrderSn, 'ZC');
            if($one_order['distributor_id']){
                $list[$paper_id]['distributor_id']=$one_order['distributor_id'];
                if (in_array($one_order['distributor_id'], $distributor_2D)) {
                    $list[$paper_id]['Shop2DBarcode']=$one_order['distributor_id'].".png";
                    $list[$paper_id]['Shop2DBarcodeHeader']=$distributor_2D_header[$one_order['distributor_id']];
                    $list[$paper_id]['Shop2DBarcodeFooter']=$distributor_2D_footer[$one_order['distributor_id']];
                    $list[$paper_id]['Shop2DBarcodeSize']=$distributor_2D_size[$one_order['distributor_id']];
                    break;
                }elseif(in_array($one_order['distributor_id'],$distributor_logo)){
                    $list[$paper_id]['ShopLogo']=$one_order['distributor_id'].".png";
                    break;
                }elseif($one_order['distributor_id'] && $one_order['distributor_id']=='2193'){//苏州乐贝母婴专营的惠氏分店
                    $list[$paper_id]['isSuzhouLebeiHuishi']="YES";
                    break;
                }elseif($one_order['distributor_id']=='2147' && $taobaoOrderSn && $pos !== false){//好奇微信店铺下好奇众筹订单
                    $list[$paper_id]['isHaoQiZhongChou']="YES";
                    $list[$paper_id]['greetings']=$greetingsArr[$taobaoOrderSn]['greetings'];
                    break;
                }  
            }
        }
    }
}

$smarty->assign('list', $list);


if('16' == $party_id){
    $sql = "select download_info from ecshop.ecs_guest_info gi
                left join ecshop.ecs_order_info oi on oi.taobao_order_sn = gi.taobao_order_sn
                left join romeo.order_shipment os on CONVERT( oi.order_id USING utf8 ) = os.order_id
                left join romeo.shipment s on s.shipment_id = os.shipment_id
                where s.shipment_id = '{$_REQUEST['shipment_id']}'
    ";
    $download_info = $db->getOne($sql);
    $smarty->assign('download_info',$download_info);
}
if ('65574' == $party_id){
    $smarty->display('shipment/shipment_gymboree_for_batch_pick.htm');
}
// elseif('65623'==$party_id){//测试用百乐顺当做one业务组调整格式
elseif('65670'==$party_id){//路易十三业务组
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];//echo "<p>".($g_no+1)." => ".$goods['goods_name']."</p>";
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['goods_price']=$goods['goods_price'];
                $or_array[$sid]['body'][$gno]['spec']=$goods['spec'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=5;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_louisxiii_for_batch_pick.htm');
}
elseif('65619'==$party_id){
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];//echo "<p>".($g_no+1)." => ".$goods['goods_name']."</p>";
                $or_array[$sid]['body'][$gno]['goods_price']=$goods['goods_price'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=7;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_origins_for_batch_pick.htm');
}
//elseif('65619'==$party_id){//测试用Origins悦木之源(65619)当做 SOFINA苏菲娜(65652) 业务组调整格式

elseif('65652'==$party_id){
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];//echo "<p>".($g_no+1)." => ".$goods['goods_name']."</p>";
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=3;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_sofina_for_batch_pick.htm');
}
//elseif('65623'==$party_id){//测试用百乐顺当做one业务组调整格式
elseif('65648'==$party_id){//正式one业务组
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
            
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=10;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_one_for_batch_pick.htm');
}
// elseif('65623'==$party_id){//测试用百乐顺当做one业务组调整格式
elseif('65651'==$party_id){//孩儿面（汉高业务组）
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
            
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=4;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_haiermian_for_batch_pick.htm');
}
// elseif('65623'==$party_id){//测试用百乐顺当做one业务组调整格式
elseif('65653'==$party_id){//资生堂业务组
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            $or_array[$sid]['header'][$order_no]['SH_sale']=$the_order['SH_sale'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
            
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=4;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_shiseido_for_batch_pick.htm');
}
elseif('65639'==$party_id){  //正式的bobbi brown
// elseif('65642'==$party_id){  //测试的bobbi brown
    $bobbi_array=array();
    foreach ($list as $sid => $shipment) {
        $bobbi_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            //echo "<p>FIND order_no=$order_no => ".$the_order['taobao_order_sn']."</p>";//pp($order);
            $bobbi_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];//echo "<p>".$order_no." => ".$order['taobao_order_sn']."</p>";
            $bobbi_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $bobbi_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $bobbi_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $bobbi_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $bobbi_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            $bobbi_array[$sid]['header'][$order_no]['BB_sale']=$the_order['BB_sale'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $bobbi_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];//echo "<p>".($g_no+1)." => ".$goods['goods_name']."</p>";
                $bobbi_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $bobbi_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $bobbi_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $bobbi_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $bobbi_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=4;
    $bobbi_array_fin=array();
    foreach ($bobbi_array as $orno => $bobbi_shipment) {
        if(count($bobbi_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($bobbi_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $bobbi_array_fin[$orno."_".$page]['header']=$bobbi_shipment['header'];
                    $bobbi_array_fin[$orno."_".$page]['saleSetList']=$bobbi_shipment['saleSetList'];
                }
                $bobbi_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $bobbi_array_fin[$orno."_".$page]['shipmentId']=$bobbi_shipment['shipmentId'];
            }
        }else{
            $bobbi_array_fin[$orno]=$bobbi_shipment;
        }
    }

    $smarty->assign('bobbi_list',$bobbi_array_fin);
    $smarty->display('shipment/shipment_bobbi_for_batch_pick.htm');
}
elseif('65628'==$party_id){  //LA MER海蓝之谜有专门的发货单
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn']; 
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            $or_array[$sid]['header'][$order_no]['BB_sale']=$the_order['BB_sale'];
            $or_array[$sid]['header'][$order_no]['LM_sale']=$the_order['LM_sale'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name']; 
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=5;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_LAMER_for_batch_pick.htm');
// shipmentId=350030101
}
elseif('65622' == $party_id) {   //babynes专有发货单
     
        $or_array=array();
        foreach ($list as $sid => $shipment) {
            $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
            $gno=1;
            foreach ($shipment['order_list'] as $order_no => $the_order) {
                $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];//echo "<p>".$order_no." => ".$order['taobao_order_sn']."</p>";
                $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
                $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
                $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
                $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
                $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
                foreach ($the_order['goods_list'] as $g_no => $goods) {
                    $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];//echo "<p>".($g_no+1)." => ".$goods['goods_name']."</p>";
                    $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                    $or_array[$sid]['body'][$gno]['product_code']=$goods['barcode'];                 
                    $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                    $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                    $gno+=1;
                }
            }
            $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
            $or_array[$sid]['saleSetList']=$saleSetList;
        }
        $itemsEachPage=8;
        $or_array_fin=array();
        foreach ($or_array as $orno => $or_shipment) {
            if(count($or_shipment['body'])>$itemsEachPage){
                $page=0;
                foreach ($or_shipment['body'] as $gno => $good_item) {
                    if($gno>$page*$itemsEachPage){
                        $page+=1;
                        $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                        $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                    }
                    $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                    $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
                }
            }else{
                $or_array_fin[$orno]=$or_shipment;
            }
        }
    
        $smarty->assign('or_list',$or_array_fin);
        $smarty->display('shipment/shipment_Babynes_for_batch_pick.htm');
}
elseif(count($distributor_id)==1 && $distributor_id[0]=='2386'){ //独立发货单   惠氏微商城 
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $or_array[$sid]['gridId']=$shipment['gridId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=8;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_HSWSC_for_batch_pick.htm');
}
elseif(count($distributor_id)==1 && $distributor_id[0]=='2071'){ //独立发货单 惠氏金宝宝
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $or_array[$sid]['gridId']=$shipment['gridId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['barcode'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=10;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_HSJBB_for_batch_pick.htm');
}
elseif(count($distributor_id)==1 && $distributor_id[0]=='2333'){ //独立发货单  亨氏唯品会 
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $or_array[$sid]['gridId']=$shipment['gridId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            $or_array[$sid]['header'][$order_no]['province']=$the_order['province'];
            $or_array[$sid]['header'][$order_no]['city']=$the_order['city'];
            $or_array[$sid]['header'][$order_no]['district']=$the_order['district'];
            $or_array[$sid]['header'][$order_no]['address']=$the_order['address'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']= empty($goods['style_barcode']) ? $goods['barcode'] : $goods['style_barcode'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=10;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);

    $smarty->display('shipment/shipment_print_for_batch_pick_2D_HSWPH.htm');
}
elseif ('65664' == $party_id) {//豪吉业务组
// elseif('65558'==$party_id){//测试用金佰利当做one业务组调整格式
    $or_array=array();
    foreach ($list as $sid => $shipment) {
        $or_array[$sid]['shipmentId']=$shipment['shipmentId'];
        $gno=1;
        foreach ($shipment['order_list'] as $order_no => $the_order) {
            $or_array[$sid]['header'][$order_no]['taobao_order_sn']=$the_order['taobao_order_sn'];
            $or_array[$sid]['header'][$order_no]['order_sn']=$the_order['order_sn'];
            $or_array[$sid]['header'][$order_no]['taobao_user_id']=$the_order['order_attribute']['TAOBAO_USER_ID'];
            $or_array[$sid]['header'][$order_no]['consignee']=$the_order['consignee'];
            $or_array[$sid]['header'][$order_no]['mobile']=$the_order['mobile'];
            $or_array[$sid]['header'][$order_no]['order_amount']=$the_order['order_amount'];
            $or_array[$sid]['header'][$order_no]['province']=$the_order['province'];
            $or_array[$sid]['header'][$order_no]['city']=$the_order['city'];
            $or_array[$sid]['header'][$order_no]['district']=$the_order['district'];
            $or_array[$sid]['header'][$order_no]['address']=$the_order['address'];
            foreach ($the_order['goods_list'] as $g_no => $goods) {
                $or_array[$sid]['body'][$gno]['goods_name']=$goods['goods_name'];
                $or_array[$sid]['body'][$gno]['goods_number']=$goods['goods_number'];
                $or_array[$sid]['body'][$gno]['product_code']=$goods['goods_id'];
                $or_array[$sid]['body'][$gno]['location_seq_id']=$goods['location_seq_id'][0];
                $or_array[$sid]['body'][$gno]['validity_batch_sn']=$goods['validity_batch_sn'];
                $gno+=1;
            }
            
        }
        $saleSetList=findSaleSetOfOrder($shipment['shipmentId']);
        $or_array[$sid]['saleSetList']=$saleSetList;
    }
    $itemsEachPage=4;
    $or_array_fin=array();
    foreach ($or_array as $orno => $or_shipment) {
        if(count($or_shipment['body'])>$itemsEachPage){
            $page=0;
            foreach ($or_shipment['body'] as $gno => $good_item) {
                if($gno>$page*$itemsEachPage){
                    $page+=1;
                    $or_array_fin[$orno."_".$page]['header']=$or_shipment['header'];
                    $or_array_fin[$orno."_".$page]['saleSetList']=$or_shipment['saleSetList'];
                }
                $or_array_fin[$orno."_".$page]['body'][$gno]=$good_item;
                $or_array_fin[$orno."_".$page]['shipmentId']=$or_shipment['shipmentId'];
            }
        }else{
            $or_array_fin[$orno]=$or_shipment;
        }
    }

    $smarty->assign('or_list',$or_array_fin);
    $smarty->display('shipment/shipment_haoji_for_batch_pick.htm');
}else if(in_array($facility_id,array('19568549','194788297')) && in_array($party_id,array(65648,65664,65651,65553))){
    $smarty->display('shipment/shipment_print_for_batch_pick.htm');
}
 else if(in_array($facility_id,array('19568549','194788297'))){
    
    $smarty->display('shipment/shipment_print_for_batch_pick_new.htm');
}
 else{
    $smarty->display('shipment/shipment_print_for_batch_pick.htm');
}

/**
 * 字符替换
 * @param array $name
 * @param int $size
 * @param string $str
 */
function string_replace($name,$size,$str){
    $str = preg_replace('/(\d+)/',"<B><span style=\"font-size:{$size}px;\">$1</span></B>",$str);
    if(is_array($name)){
        foreach ($name as $str_name){
            $str = preg_replace("/{$str_name}/","<span style=\"font-size:{$size}px;\">{$str_name}</span>",$str);
        }
    }else{
        $str = preg_replace("/{$name}/","<span style=\"font-size:{$size}px;\">{$name}</span>",$str);
    }
    
    return $str;
}
//按grid_id排序
function sort_list_by_grid_id($a, $b)
{
    if($a['gridId'] == $b['gridId'])
        die('grid id 重复');
    return ($a['gridId'] < $b['gridId'] ? -1 : 1);
}

// 取得生产日期/批次号
function get_product_validity_batch_sn($product_id,$order_id) {
    global $db;
    $sql = "select oid.product_id,
                ifnull(left(ii.validity,10),'1970-01-01') as validity,
                ifnull(ii.batch_sn,'') as batch_sn,
                ifnull(sum(im.quantity),sum(oid.GOODS_NUMBER)) as quantity
                from 
                romeo.order_inv_reserved_detail oid
                left join romeo.order_inv_reserved_inventory_mapping im ON oid.order_inv_reserved_detail_id = im.order_inv_reserved_detail_id
                left join romeo.inventory_item ii ON im.inventory_item_id = ii.inventory_item_id
                where oid.order_id = '{$order_id}' and oid.product_id = '{$product_id}'
                group by ii.validity,ii.batch_sn
    ";
//  var_dump('get_product_validity_batch_sn sql;');var_dump($sql);
    $validity_quantitys = $db->getAll($sql);
    
    $exist_batch_sn = false;
    if(!empty($validity_quantitys)) {
        foreach($validity_quantitys as $validity_quantity) {
            if($validity_quantity['batch_sn']) {
                $exist_batch_sn = true;
            }
        }
    }
    
    if(!$exist_batch_sn) return null;
//  var_dump('$validity_quantitys ;');var_dump($validity_quantitys);

    return $validity_quantitys;
}

//取得库位列表
function facility_location_list_by_product_in_new_wms($product_id, $facility_id)
{
    global $db;
    $sql = "select distinct(il.location_barcode) from romeo.inventory_location il
                left join romeo.location l on il.location_barcode = l.location_barcode
                where il.product_id = '{$product_id}' and il.goods_number > 0 and il.facility_id = '{$facility_id}'
                and l.location_type='IL_LOCATION'
    ";
    $res = $db->getAll($sql);
    
    $result = array();
    foreach($res as $single_res)
    {
        array_push($result, $single_res['location_barcode']);
    }

    $result=sort_wms_location($result);

    return $result;
}
function sort_wms_location($locations){
    $second_locations=array();
    foreach ($locations as $key => $value) {
        $parts=explode('-', $value);
        $newKey=$parts[0].'-'.$parts[1].'-'.$parts[3].'-'.$parts[2];
        $second_locations[$newKey]=$value;
    }
    ksort($second_locations);
    $locations=array();
    foreach ($second_locations as $value) {
        array_push($locations, $value);
    }
    return $locations;
}

function cmp_wms_location($a, $b)
{
    // print_r($a);
    // print_r($b);

    if((!is_array($a) || count($a)<=0)&&(!is_array($b) || count($b)<=0))return 0;
    if(!is_array($a) || count($a)<=0)return 1;
    else if(!is_array($b) || count($b)<=0)return -1;

    $parts_a=explode('-', $a['location_seq_id'][0]);
    $newKey_a=$parts_a[0].'-'.$parts_a[1].'-'.$parts_a[3].'-'.$parts_a[2];
    $parts_b=explode('-', $b['location_seq_id'][0]);
    $newKey_b=$parts_b[0].'-'.$parts_b[1].'-'.$parts_b[3].'-'.$parts_b[2];
    if ($newKey_a == $newKey_b) {
        return 0;
    }
    return ($newKey_a > $newKey_b) ? 1 : -1;
}


/**
携帯電話の最中の四位を隠す
**/
function keitai_angouka($bangou){
    if(strlen($bangou)==11)
        return substr($bangou, 0,3).'****'.substr($bangou,7);
    else
        return substr($bangou, 0,-4).'****';
}

/**
订单套餐信息查询
**/
function findSaleSetOfOrder($shipment_id){
    global $db;
    $sql="SELECT 
        -- eoi.order_id,
        -- oga.`name`,
        -- oga.`value`,
        DISTINCT dgg.`code`,
        dgg.`name`
    FROM
        romeo.order_shipment os
    LEFT JOIN ecshop.ecs_order_info eoi ON os.ORDER_ID = eoi.order_id -- os.ORDER_ID=CONVERT(eoi.order_id USING utf8)
    LEFT JOIN ecshop.ecs_order_goods eog ON eog.order_id = eoi.order_id
    LEFT JOIN ecshop.order_goods_attribute oga ON oga.order_goods_id = eog.rec_id
    AND oga.`name` = 'OUTER_IID'
    LEFT JOIN ecshop.distribution_group_goods dgg ON dgg.`code` = oga.`value`
    WHERE
        -- eoi.party_id = 65619 -- eoi.order_id=4704588
        os.SHIPMENT_ID = '{$shipment_id}'
    AND oga.order_goods_id IS NOT NULL
    GROUP BY
        dgg.`code`";
    $saleSetList=$db->getAll($sql);

    if(!is_array($saleSetList) || count($saleSetList)<=0)return array();
    else{
        $makoto_set_risuto=array();
        foreach ($saleSetList as $sslid => $saleSet) {
            $code=$saleSet['code'];
            $sql_check_nise_set="SELECT count(*) from 
                romeo.order_shipment os
                left join
                ecshop.ecs_order_goods eog on os.order_id=eog.order_id
                left JOIN
                ecshop.order_goods_attribute oga 
                on oga.order_goods_id = eog.rec_id
                where 
                os.shipment_id='{$shipment_id}' AND oga.`name` = 'OUTER_IID'  AND oga.`value`='{$code}'";
            $r=$db->getOne($sql_check_nise_set);
            if($r && $r>1){
                $full_name=$saleSet['name'];
                $space_index=strpos($full_name, ' ');
                if($space_index)$name=substr($full_name, 0,$space_index);
                else $name=$full_name;
                $makoto_set_risuto[]=array(
                    'code'=>$saleSet['code'],
                    'name'=>$name
                );
            }
        }
        return $makoto_set_risuto;
    }
}
 
 //根据发货单号查询出其对应的主订单的类型
function getOrderTypeZhixiaoFenxiao($shipment_id){
    global $db; 
    $sql="SELECT
            md.type
        FROM
            romeo.shipment s
        LEFT JOIN ecshop.ecs_order_info o ON s.primary_order_id = o.order_id
        LEFT JOIN ecshop.distributor d ON o.distributor_id = d.distributor_id
        LEFT JOIN ecshop.main_distributor md ON md.main_distributor_id = d.main_distributor_id
        WHERE
            s.SHIPMENT_ID ='{$shipment_id}'
        LIMIT 1";
    $order_type="";
    $order_type=$db->getOne($sql);
    if($order_type==null||$order_type=="")
        return "unKnow";
    if($order_type!="fenxiao"&&$order_type!="zhixiao")
        return "unKnow";
    return $order_type;
}

//根据order_number查找好奇众筹订单的祝福语信息
function getGreetingsByTaobaoOrderSn($taobaoOrderSnStr){
    global $db;
    $sql="SELECT order_number, greetings FROM sync_weixin_order_info where order_number in ($taobaoOrderSnStr);";
    $results=$db->getAll($sql);
    $resultArr = array();
    foreach ($results as $result) {
        $order_number=$result['order_number'];
        $resultArr[$order_number]=$result;
    }
    return $resultArr;
}