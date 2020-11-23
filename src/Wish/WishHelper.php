<?php

namespace Wish;

include_once dirname ( '__FILE__' ) . './mysql/dbhelper.php';
include_once dirname ( '__FILE__' ) . './user/wconfig.php';
include_once dirname ( '__FILE__' ) . './model/order.php';
include_once dirname ( '__FILE__' ) . './model/productinventory.php';
include_once dirname ( '__FILE__' ) . './wishpost/Wishposthelper.php';
include_once dirname ( '__FILE__' ) . './model/senderinfo.php';
include_once dirname ( '__FILE__' ) . './cpws/CPWSManager';

use mysql\dbhelper;
use model\order;
use wishpost\Wishposthelper;
use model\senderinfo;
use model\productinventory;
use cpws\CPWSManager;

class WishHelper {
	private $dbhelper;
	private $cpwsmanager;
	
	public function __construct(){
		$this->dbhelper = new dbhelper();
	}
	
	// save the unfulfilled orders into db;
	public function saveOrders($unfulfilled_orders, $accountid) {
		$preTransactionid = "";
		$preOrderNum = 0;
		foreach ( $unfulfilled_orders as $cur_order ) {
			$shippingDetail = $cur_order->ShippingDetail;
			$orderarray = array ();
			$orderarray ['transactionid'] = $cur_order->transaction_id;
			$orderarray ['orderid'] = $cur_order->order_id;
			
			if (strcmp ( $cur_order->transaction_id, $preTransactionid ) == 0) { // there are more than one orders in a transaction.
				$preOrderNum = $preOrderNum + 1;
				$orderarray ['orderNum'] = $preOrderNum;
			} else {
				$orderarray ['orderNum'] = 0;
				$preTransactionid = $cur_order->transaction_id;
				$preOrderNum = 0;
			}
			
			$orderarray ['accountid'] = $accountid;
			$orderarray ['ordertime'] = $cur_order->order_time;
			
			$orderarray ['orderstate'] = $cur_order->state;
			$orderarray ['sku'] = $cur_order->sku;
			$orderarray ['productname'] = $cur_order->product_name;
			$orderarray ['productimage'] = $cur_order->product_image_url;
			if (! empty ( $cur_order->color )) {
				$orderarray ['color'] = $cur_order->color;
			} else {
				$orderarray ['color'] = "";
			}
			
			if (! empty ( $cur_order->size )) {
				$orderarray ['size'] = $cur_order->size;
			} else {
				$orderarray ['size'] = "";
			}
			
			$orderarray ['price'] = $cur_order->price;
			$orderarray ['cost'] = $cur_order->cost;
			$orderarray ['shipping'] = $cur_order->shipping;
			$orderarray ['shippingcost'] = $cur_order->shipping_cost;
			$orderarray ['quantity'] = $cur_order->quantity;
			$orderarray ['totalcost'] = $cur_order->order_total;
			$orderarray ['provider'] = '';
			$orderarray ['tracking'] = '';
			$orderarray ['name'] = $shippingDetail->name;
			$orderarray ['streetaddress1'] = $shippingDetail->street_address1;
			if (! empty ( $shippingDetail->street_address2 )) {
				$orderarray ['streetaddress2'] = $shippingDetail->street_address2;
			} else {
				$orderarray ['streetaddress2'] = "";
			}
			
			$orderarray ['city'] = $shippingDetail->city;
			if (! empty ( $shippingDetail->state )) {
				$orderarray ['state'] = $shippingDetail->state;
			} else {
				$orderarray ['state'] = "";
			}
			$orderarray ['zipcode'] = $shippingDetail->zipcode;
			$orderarray ['phonenumber'] = $shippingDetail->phone_number;
			$orderarray ['countrycode'] = $shippingDetail->country;
			
			$orderarray ['orderstatus'] = '0'; // 0: new order; 1: applied tracking number; 2: has download label; 3: has uploaded tracking number;
			
			
			$orderarray['isWishExpress'] = $cur_order->is_wish_express;
			$orderarray['requireDeliveryConfirmation'] = $cur_order->requires_delivery_confirmation;
			$insertResult = $this->dbhelper->insertOrder ( $orderarray );
		}
	}
	
	public function getUserLabelsArray($userid){
		$labels = array();
		$labelResult = $this->dbhelper->getUserLabels($userid);
		while ($label = mysql_fetch_array ( $labelResult )) {
			$labels[$label['parentsku']] = $label['cn_name']."|".$label['en_name'];
		}
		return $labels;
	}
	
	public function getWEUserLabelsArray($userid){
		$labels = array();
		$welabels = $this->dbhelper->getWEUserLabels($userid);
		while($curlabel = mysql_fetch_array($welabels)){
			$labels[$curlabel['parent_sku']] = $curlabel['label_id'];
		}
		return $labels;
	}
	
	public function getPidBySKU($accountid,$subsku){
		$productid = '';
		$pr = $this->dbhelper->getProductIDByVSKU($accountid, $subsku);
		if($pvalue = mysql_fetch_array($pr)){
			$productid = $pvalue['product_id'];
		}
		return $productid;
	}
	
	public function getPVaridBySKU($accountid,$subsku){
		$productvarid = '';
		$pr = $this->dbhelper->getProductVarIDByVSKU($accountid, $subsku);
		if($pvalue = mysql_fetch_array($pr)){
			$productvarid = $pvalue['id'];
		}
		return $productvarid;
	}
	
	public function getParentSKUBySKU($accountid,$subsku){
		$productid = $this->getPidBySKU($accountid, $subsku);
		
		$pidresult = $this->dbhelper->getProductSKUByID($productid);
		if($psku = mysql_fetch_array($pidresult)){
			$parentSKU = $psku['parent_sku'];
			return $parentSKU;
		}
		return NULL;
	}
	
	public function getLabelsArray($userLabels){
		$labelsarray = array();
		foreach ($userLabels as $lKey=>$lValue){
			$labelsarray[] = $lValue;
		}
		return $labelsarray;
	}
	
	public function getCNENLabel($labels,$sku){
		$curLabel = $labels[$sku];
		$cnenlabel = explode('|',$curLabel);
		if($cnenlabel[0] == null)
			$cnenlabel[0] = "";
		if($cnenlabel[1] == null)
			$cnenlabel[1] = "";
		return $cnenlabel;
	}
	
	public function processWEOrder($accountid, $currentorder){
		
		echo "<br/>***********START TO PROCESS WE ORDER".$currentorder['sku']."*************";
		//get shippingmethod and WEProductSKU. 
		$pvroductid = $this->getPVaridBySKU($accountid, $currentorder['sku']);
		echo "<br/>************productid:".$pvroductid."*************";
		$shippingmethodresult = $this->dbhelper->getWEShippingMethod($pvroductid, $currentorder['countrycode']);
		if($shippingmethod = mysql_fetch_array($shippingmethodresult)){
			$expresscode = $shippingmethod['express_code'];
			$providername = $shippingmethod['provider_name'];
			
			if(!empty($expresscode)){
				$currentorder['warehouse_code'] = substr($expresscode,0,4);
				$currentorder['shippingmethod'] =substr($expresscode,5);
				echo "<br/>warehousecode:".$currentorder['warehouse_code'].",shippingmethod:".$currentorder['shippingmethod'];
			}
			$currentorder['provider'] = $providername;
			echo "<br/>get expresscode:".$expresscode.", provider:".$providername;
		}
		
		$weproductidresult = $this->dbhelper->getWEProductID($accountid, $pvroductid);
		if($weproductid = mysql_fetch_array($weproductidresult)){
			$weproductidvalue = $weproductid['label_id'];
			$weproductskuresult = $this->dbhelper->getWEProductSKUBYID($weproductidvalue);
			if($weproductsku = mysql_fetch_array($weproductskuresult)){
				$weproductskuvalue = $weproductsku['weproductsku'];
				$currentorder['WEProductSKU'] =$weproductskuvalue; 
				echo "<br/>get weproductSKU:".$weproductskuvalue;
			}
		}
		
		if(isset($currentorder['shippingmethod']) && isset($currentorder['WEProductSKU'])){
			if(!isset($this->cpwsmanager))
				$this->cpwsmanager = new CPWSManager();
			
			$rs = $this->cpwsmanager->processorder($currentorder);
			
			if(strcmp($rs['ask'],'Success') == 0){
				// get ordercode and insertinto weorders.
				$orderinfo = array(
						'orderid' => $currentorder['orderid'],
						'weordercode' => $rs['order_code']
				);
				$this->dbhelper->addweorderinfo($orderinfo);
					
				// update orders.orderstatus to 2;
				$currentorder ['orderstatus'] = ORDERSTATUS_DOWNLOADEDLABEL;
				$this->dbhelper->updateOrder ( $currentorder );
			}else{
				echo "<br/>****************ERROR, failed to create cpws order because of " . $rs['message']."  of order id ".$currentorder['orderid'].".**********************";
			}
			
		}else{
			echo "<br/>****************ERROR, didn't get WEProductSKU or shippingmethod of order id ".$currentorder['orderid'].".**********************";
		}
	
	}
	
	public function applyTrackingsForOrders($userid,$accountid,$labels,$expressinfo){
		
		$yanwenExpresses = $this->getChildrenExpressinfosOF(PROVIDER_YANWEN);
		$wishpostExpresses = $this->getChildrenExpressinfosOF(PROVIDER_WISHPOST);
		$expressinfos = $this->getUserExpressInfos($userid,0);
		
		$labels = $this->getUserLabelsArray ( $userid );
		$expressinfo = $this->getExpressInfo ( $userid );
		
		
		$post_header = array (
				'Authorization: basic '.$expressinfo[YANWEN_API_TOKEN],
				'Content-Type: text/xml; charset=utf-8'
		);
		
		$wishpostorders = array();
		$ordersNoTracking = $this->dbhelper->getOrdersNoTracking ( $accountid );
		echo "get ordersNoTracking:" . mysql_num_rows ( $ordersNoTracking ) . "<br/>";
		$preTransactionid = "";
		while ( $orderNoTracking = mysql_fetch_array ( $ordersNoTracking ) ) {
			echo "<br/>********get is wishexpress".$orderNoTracking['iswishexpress']."*********";
			//process we order firstly;
			if(strcmp($orderNoTracking['iswishexpress'],'True') == 0 ){
				$this->processWEOrder($accountid,$orderNoTracking);
				continue;
			}
			
			//exclude Ebay User:
			if($accountid != 0){
				$curProductid = $this->getPVaridBySKU($accountid, $orderNoTracking['sku']);
				echo "<br/>***********CURR:".$curProductid;
				$curCountrycode = $orderNoTracking['countrycode'];
					
				$curExpress = $expressinfos[$curProductid.'|'.$curCountrycode];
				$expressid = explode ( "|", $curExpress )[0];
				$expressValue = $yanwenExpresses[$expressid];
				if($expressValue == null){
					$expressValue = $wishpostExpresses[$expressid];
					if($expressValue == null){
						echo "<br/>".$orderNoTracking['sku']." use the other logistic";
					}else{
						$orderNoTracking['expressValue'] = $expressValue;
						$wishpostorders[] = $orderNoTracking;
					}
					continue;
				}
			}
			
			//if (strcmp ( $orderNoTracking ['countrycode'], "US" ) != 0) {
				$xml = simplexml_load_string ( '<?xml version="1.0" encoding="utf-8"?><ExpressType/>' );
					
				$epcode = $xml->addChild ( "Epcode" );
				$ywuserid = $xml->addChild ( "Userid", $expressinfo[YANWEN_USER_ATTR] ); // *
					
				$orderTotalPrice = $orderNoTracking ['totalcost'];
				$orderQuantity = $orderNoTracking ['quantity'];
				$intPrice = intval ( $orderTotalPrice );
					
				$tempSKU = $orderNoTracking ['sku'];
				$parentSKU = $this->getParentSKUBySKU($accountid, $tempSKU);
				if($parentSKU == null){
					echo "Failed to get parentsku of sku:".$tempSKU."<br/>";
					continue;
				}
				
				if ($orderNoTracking ['orderNum'] != 0) {
					$preGoodsNameEn = $preGoodsNameEn . $this->getTempSKU($parentSKU) . $orderNoTracking ['color'] . $this->getTempsize($orderNoTracking ['size']) . "*" . $orderQuantity;
					$preTransactionid = $orderNoTracking ['transactionid'];
					$preOrderQuantity = $preOrderQuantity + $orderQuantity;
					$prePrice = $prePrice + $intPrice;
				} else {
					/* if (strcmp ( $preGoodsNameEn, "" ) != 0 && strcmp ( $orderNoTracking ['transactionid'], $preTransactionid ) == 0) {
						$channel = $xml->addChild ( "Channel", "154" ); // *
						$orderNoTracking ['provider'] = "ChinaAirPost";
					} else {
						$preTransactionid = $orderNoTracking ['transactionid'];
						//if (strcmp ( $orderQuantity, "1" ) == 0 && $intPrice < 7) {
						if ($intPrice < 7) {
							$channel = $xml->addChild ( "Channel", "105" ); // *
							$orderNoTracking ['provider'] = "YanWen";
						} else {
							$channel = $xml->addChild ( "Channel", "154" ); // *
							$orderNoTracking ['provider'] = "ChinaAirPost";
						}
					}
					
					if (strcmp ( $orderNoTracking ['countrycode'], "US" ) == 0 && strcmp ($orderNoTracking ['provider'],"ChinaAirPost") == 0){// process by EUB;
						$preGoodsNameEn = "";
						continue;
					} */
					
					//for Ebay User:
					if($accountid == 0){
						if ($intPrice < 7) {
							$channel = $xml->addChild ( "Channel", "105" ); // *
							$orderNoTracking ['provider'] = "YanWen";
						} else {
							$channel = $xml->addChild ( "Channel", "154" ); // *
							$orderNoTracking ['provider'] = "ChinaAirPost";
						}
					}else{
						$expressValue = explode ( "|",$expressValue);
						$channel = $xml->addChild ( "Channel", $expressValue[0]);
						$orderNoTracking ['provider'] = $expressValue[1];
						echo "<br/>currentorder ".$orderNoTracking['sku']." use the logistic:".$expressValue[0].$expressValue[1];
					}
		
					
					$combinedPrice = $intPrice + $prePrice;
					$combinedQuantity = $orderQuantity + $preOrderQuantity;
					
					
					//$userOrderNum = $xml->addChild ( "UserOrderNumber", $accountid . "_" . substr ( 10000 * microtime ( true ), 10, 4 ) );
					$sendDate = $xml->addChild ( "SendDate", date ( 'Y-m-d  H:i:s' ) ); // *
					$quantity = $xml->addChild ( "Quantity", $combinedQuantity ); // *
					$packageno = $xml->addChild ( "PackageNo" );
					$insure = $xml->addChild ( "Insure" );
					$memo = $xml->addChild ( "Memo" );
		
					$Receiver = $xml->addChild ( "Receiver" );
					$RcUserid = $Receiver->addChild ( "Userid", userid ); // *
					$RcName = $Receiver->addChild ( "Name", $orderNoTracking ['name'] ); // *
					$RcPhone = $Receiver->addChild ( "Phone", $orderNoTracking ['phonenumber'] );
					$RcMobile = $Receiver->addChild ( "Mobile" );
					$RcEmail = $Receiver->addChild ( "Email" );
					$RcCompany = $Receiver->addChild ( "Company" );
					$RcCountry = $Receiver->addChild ( "Country", $orderNoTracking ['countrycode'] );
					$RcPostcode = $Receiver->addChild ( "Postcode", $orderNoTracking ['zipcode'] ); // *
					$RcState = $Receiver->addChild ( "State", $orderNoTracking ['state'] ); // *
					$RcCity = $Receiver->addChild ( "City", $orderNoTracking ['city'] ); // *
					$RcAddress1 = $Receiver->addChild ( "Address1", $orderNoTracking ['streetaddress1'] ); // *
					$RcAddress2 = $Receiver->addChild ( "Address2", $orderNoTracking ['streetaddress2'] );
		
					$Goods = $xml->addChild ( "GoodsName" );
					$gsUserid = $Goods->addChild ( "Userid", $expressinfo[YANWEN_USER_ATTR] ); // *
		
					/* $tempSKU = str_replace(' ','',$tempSKU);
					$tempSKU = str_replace('.','',$tempSKU);
					$tempSKU = str_replace('&amp;','',$tempSKU);
					$tempSKU = str_replace('&quot;','',$tempSKU); */
					
					/* $tempSKU = str_replace('&amp;','AND',$tempSKU);
					
					$tempsku = str_replace('&amp;','',$cur_order ['sku']);
					$tempsku = str_replace('&quot;','',$tempsku); */
					
					$temppid = $this->getPVaridBySKU($accountid, $tempSKU);
					$gsLabel = $this->getCNENLabel($labels, $temppid);
					$gsNameCh = $Goods->addChild ( "NameCh", $gsLabel[0] ); // *
					
					
					$tempParSKU = $this->getTempSKU($parentSKU);
					$tempSize = $this->getTempsize($orderNoTracking ['size']);
					
					$orderinfo = $accountid.'_'.$tempParSKU . $orderNoTracking ['color'] . $tempSize . "*" . $orderQuantity.";" . $preGoodsNameEn;
					//$tempEn = $gsLabel[1] ." :". $tempSKU . "-" . $orderNoTracking ['color'] . "-" . $orderNoTracking ['size'] . "*" . $orderQuantity.";" . $preGoodsNameEn;
					$orderinfo = str_replace('&quot;','',$orderinfo);//英文品名不能包含特殊字符，因此替换掉。
					$orderinfo = str_replace('&amp;','',$orderinfo);//英文品名不能包含空格，因此替换掉。
					$orderinfo = str_replace(' ','',$orderinfo);//英文品名不能包含空格，因此替换掉。
					$orderinfo = str_replace('"','',$orderinfo);//英文品名不能包含空格，因此替换掉。
					$tempEn = $gsLabel[1] ." :".$orderinfo;
					if(strlen($tempEn)>=50){
						$tempEn = substr($tempEn,0,45).'...';
					}
					
					//订单单号只允许使用字母、数字和'-'、'_'字符
					$orderinfo = str_replace(':','',$orderinfo);//订单单号不能包含:，因此替换掉。
					$orderinfo = str_replace('*','_',$orderinfo);//订单单号不能包含*，因此替换掉。
					$orderinfo = str_replace(';','',$orderinfo);//订单单号不能包含;，因此替换掉。
					$orderinfo = str_replace('H_4PCSALK','4ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HSRingT2','Ring',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_FrenchDog','FrDog',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_6PCSALK','6ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('matdwsunflower','matsun',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('nk8023-blue','NK8023',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_2350_10pcs_M','10_2350',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_SDiceT2','dice',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('lovelypiranhaearring','flwEar',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_GdiceT1','dice',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_bulldog','FrDog',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_4pdalk','4ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_5GSStraw','5strw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_ChatRingT4','Ring',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_LtsRingT3','Ring',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DrinkingStrawM4','straw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DiceFp4','dice',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_10Wristband','10wrst',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_TSTStrawM2','straw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_GStrawM1','straw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_WstbandT2','10wrst',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_PtEarringT1','flwEar',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_GftEarringT4','flwEar',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_alk','ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_TGSStrawM3','straw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HFT4','HF87',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HFSolidT2','HF87',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HFPureT1','HF87',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('K141_AY893','AY893',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('Y_wstkZY1025','ZY1025',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DZ','DZ',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DrinkingStrawM4','straw',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_CyEarringT2','flwEar',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_SnowEarT1','SnowEar',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_NKDT1','NKD',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_ShoesA16','Shoe',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('Y_CtALK','ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_10ALK','10ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_FxPant2','FxPant',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_SyPant3','FxPant',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DIYClock','Clok',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('Y_HmALK','ALK',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HFD87T3','HF87',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_KeyRingT2','Key',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_KeyScrewDv','Key',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_TInsoleT1','TShoepad',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_HeelInsoleT1','Silconpad',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_10Hairope','10Hairope',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_toltStkT1','ToiletStk',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_RoomStkT1','Doorlabel',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					$orderinfo = str_replace('H_DoorStkT2','Doorlabel',$orderinfo);//太长的SKU名称替换为简短的SKU代码;
					
					
					/* $sindex = strpos($orderinfo,'(');
					if($sindex === false){
					}else{
						$orderinfo = substr_replace($orderinfo,'',$sindex);
					} */
					/* $orderinfo = str_replace('L(28-32)','L',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('XL(30-34)','XL',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('XXL(32-36)','2X',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('M(26-30)','M',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('XXXL(34-38)','3X',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('8XL(40-45)','8X',$orderinfo);//太长的尺码替换
					 */
					$orderinfo = str_replace('ChineseSize8XL','8X',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('XXXL','3X',$orderinfo);//太长的尺码替换
					$orderinfo = str_replace('3XL','3X',$orderinfo);//太长的尺码替换
					
					$orderinfo = str_replace('lightyellow','ylw',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('yellow','ylw',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('brown','brw',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('blue','blu',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('purple','pup',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('black','bak',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('nude','nud',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('Army Green','Grn',$orderinfo);//太长的颜色替换;
					$orderinfo = str_replace('Silver','sil',$orderinfo);//太长的颜色替换;
					
					if(strlen($orderinfo)>=40){
						$orderNum = substr ( 10000 * microtime ( true ), 10, 4 ). '_'. substr($orderinfo,0,40);
					}else{
						$orderNum = substr ( 10000 * microtime ( true ), 10, 4 ). '_'. $orderinfo;
					}
					echo "<br/> order info:".$orderNum;
					$userOrderNum = $xml->addChild ( "UserOrderNumber", $orderNum);
					
					
				/* 	$tempEn = str_replace('&amp;','AND',$tempEn);
					$tempEn = str_replace(' ','_',$tempEn);
					$tempEn = str_replace('&','AND',$tempEn);
					 */
					echo "<br/>English Name:".$tempEn;
					$gsNameEn = $Goods->addChild ( "NameEn", $tempEn); // *
					//$gsNameEn = $Goods->addChild ( "NameEn", $gsLabel[1] ." :". $orderNoTracking ['sku'] . "-" . $orderNoTracking ['color'] . "-" . $orderNoTracking ['size'] . "*" . $orderQuantity.";" . $preGoodsNameEn ); // *
		
					//$gsMoreGoodsName = $Goods->addChild ( "MoreGoodsName",$gsLabel[1] ." :". $orderNoTracking ['sku'] . "-" . $orderNoTracking ['color'] . "-" . $orderNoTracking ['size'] . "*" . $orderQuantity. ";" . $preGoodsNameEn );
					$gsMoreGoodsName = $Goods->addChild ( "MoreGoodsName",$gsLabel[1]);
					
					$preGoodsNameEn = "";
					
					if($combinedPrice >40){
						$combinedWeight = 500 * $combinedQuantity;
					}else{
						$combinedWeight = 100 * $combinedQuantity;
					}
					
					$gsWeight = $Goods->addChild ( "Weight", $combinedWeight ); // *
					$prePrice = 0;
					$preOrderQuantity = 0;
					
					$gsDeclaredValue = $Goods->addChild ( "DeclaredValue", "4" ); // *
					$gsDeclaredCurrency = $Goods->addChild ( "DeclaredCurrency", "USD" ); // *
					$GsHsCode = $Goods->addChild ( "HsCode" );
		
					$XMLString = $xml->asXML ();
					echo "<br/>XMLString:";
					print_r($XMLString);
					$curl = curl_init ();
					$url = $expressinfo[YANWEN_SERVICE_URL] . "/Users/" . $expressinfo[YANWEN_USER_ATTR] . "/Expresses";
					curl_setopt ( $curl, CURLOPT_URL, $url );
					curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, true );
					curl_setopt ( $curl, CURLOPT_POST, true );
					curl_setopt ( $curl, CURLOPT_HTTPHEADER, $post_header );
					curl_setopt ( $curl, CURLOPT_POSTFIELDS, $XMLString );
					$result = curl_exec ( $curl );
					$error = curl_error ( $curl );
					curl_close ( $curl );
					$resultXML = simplexml_load_string ( $result );
					echo "<br/>resultXML:";
					var_dump ( $resultXML );
					$response = $resultXML->Response;
					$trackingnumber = $response->Epcode;
					$success = $response->Success;
					if ($trackingnumber == null || strcmp ( $trackingnumber, "" ) == 0) {
						$createExpress = $resultXML->CreatedExpress;
						$trackingnumber = $createExpress->Epcode;
						if ($trackingnumber == null || strcmp ( $trackingnumber, "" ) == 0) {
							$trackingnumber = $createExpress->YanwenNumber;
						}
					}
					echo "tracking:" . $trackingnumber . "success:" . $success;
					if (strcmp ( $success, "true" ) == 0) {
						$printTrackingnumbers = $printTrackingnumbers . $trackingnumber . ",";
						$orderNoTracking ['tracking'] = $trackingnumber;
						$orderNoTracking ['orderstatus'] = '1';
						$this->dbhelper->updateOrder ( $orderNoTracking );
					}
					if (! empty ( $error )){
						echo "<br/>Failed to get the tracking from YW, error:" . $error . "<br/>";
						echo "<br/>post header:".$post_header[0]."<br/>";
						var_dump($XMLString);
						echo "<br/>result:".$result."<br/>";
					} 
						
				}
			//}
		}
		
		
		
		//process wishpost orders;
		$ordersobj = array();
		$countries = $this->getCountrynames();
		
		$preGoodsNameEn = "";
		foreach ($wishpostorders as $curorder){
			if ($curorder ['orderNum'] != 0) {
				$preGoodsNameEn = $preGoodsNameEn . $curorder ['sku'] . "-" . $curorder ['color'] . "-" . $curorder ['size'] . "*" . $curorder ['quantity'];
			} else {
				$orderobj = new order();
				$orderobj->guid = $curorder ['transactionid'];
				$expressValue = explode ( "|",$curorder['expressValue']);
				$orderobj->otype = $expressValue[0];
				
				$orderobj->to = $curorder ['name'];
				$orderobj->recipient_country = 	$countries[$curorder ['countrycode']];
				$orderobj->recipient_country_short = $curorder ['countrycode'];
				$orderobj->recipient_province = $curorder ['state'];
				$orderobj->recipient_city = $curorder ['city'];
				$orderobj->recipient_address = $curorder ['streetaddress1'].' '.$curorder ['streetaddress2'];
				$orderobj->recipient_postcode = $curorder ['zipcode'];
				$orderobj->recipient_phone = $curorder ['phonenumber'];
				$orderobj->type_no = 1;
				$orderobj->from_country = "CN";
				
				$tempSKU = $curorder ['sku'];
				/* $tempSKU = str_replace(' ','_',$tempSKU);
				$tempSKU = str_replace('&amp;','AND',$tempSKU); */
				$temppid = $this->getPVaridBySKU($accountid, $tempSKU);
				$gsLabel = $this->getCNENLabel($labels, $temppid);
				$gsNameCh = $gsLabel[0]; // *
				$gsNameEn = $gsLabel[1];
						
				//$orderobj->content = $gsNameEn.":".$curorder ['sku'] . "-" . $curorder ['color'] . "-" . $curorder ['size'] . "*" . $curorder ['quantity'].";" . $preGoodsNameEn;
				$orderobj->content = $gsNameEn;
				
				$orderobj->num = $curorder ['quantity'];
				
				$orderTotalPrice = $curorder ['totalcost'];
				if($orderTotalPrice>5){
					$orderobj->weight = $orderTotalPrice/100;
				}else{
					$orderobj->weight = "0.05";
				}
				$orderobj->single_price = 5;
				$orderobj->trande_no = $curorder ['transactionid'];
				$orderobj->trade_amount = $orderTotalPrice;
				/*
				 *  WISH邮平邮=0
					WISH邮挂号=1
					--------------------------------------
					DLP平邮=9-0
					DLP挂号=9-1
					DLE=10-0
					E邮宝=11-0
					英伦速邮小包=14-0
					欧洲经济小包=200-0
					欧洲标准小包=201-0
				 * */
				//$orderobj->user_desc = $accountid . "_" .$gsNameEn.$gsNameCh.substr ( 10000 * microtime ( true ), 10, 4 ).$orderobj->content;
				$orderobj->user_desc = $accountid . "_" .$gsNameCh.$gsNameEn.":".$curorder ['sku'] . "-" . $curorder ['color'] . "-" . $curorder ['size'] . "*" . $curorder ['quantity'].";" . $preGoodsNameEn;
				if(strcmp($orderobj->otype,'0')==0 || strcmp($orderobj->otype,'1') ==0){// WISH邮平邮 和 WISH邮挂号 
					$orderobj->user_desc = $accountid .substr ( 10000 * microtime ( true ), 10, 4 );
					$orderobj->content = $gsNameEn.":".$curorder ['sku'] . "-" . $curorder ['color'] . "-" . $curorder ['size'] . "*" . $curorder ['quantity'].";" . $preGoodsNameEn;
				}
				if(strcmp($orderobj->otype,'200-0') == 0 || strcmp($orderobj->otype,'201-0') == 0){//欧洲经济小包和欧洲标准小包
					$orderobj->user_desc .= $orderobj->to.$orderobj->recipient_country_short;
				}
				$preGoodsNameEn = "";
				
				$ordersobj[] = $orderobj;
			}
		}
		
		if(count($ordersobj)>0){
			$senderinfo = new senderinfo();
			$senderinfo->receive_from = $expressinfo[WISHPOST_RECEIVEFROM];
			$senderinfo->receive_province = $expressinfo[WISHPOST_RECEIVEPROVINCE];
			$senderinfo->receive_city = $expressinfo[WISHPOST_RECEIVECITY];
			$senderinfo->receive_addres = $expressinfo[WISHPOST_RECEIVEADDRESS];
			$senderinfo->receive_phone = $expressinfo[WISHPOST_RECEIVEPHONE];
			$senderinfo->warehouse_code = $expressinfo[WISHPOST_WAREHOUSECODE];
			$senderinfo->doorpickup = $expressinfo[WISHPOST_DOORPICKER];
			
			$senderinfo->from = $expressinfo[WISHPOST_FROM];
			$senderinfo->sender_province = $expressinfo[WISHPOST_SENDERPROVINCE];
			$senderinfo->sender_city = $expressinfo[WISHPOST_SENDERCITY];
			$senderinfo->sender_addres = $expressinfo[WISHPOST_SENDERADDRESS];
			$senderinfo->sender_phone = $expressinfo[WISHPOST_SENDERPHONE];
			
			$wishposthelper = new Wishposthelper();
			$ordersreult = $wishposthelper->createorders($accountid, $ordersobj, $senderinfo);
			//update order data;
			$barcodes = $ordersreult->barcodes;
			foreach ($barcodes as $key=>$value){
				echo "<br/>key:".$key."=>".$value;
				$trackinginfo = array();
				//$update_sql = "UPDATE orders set provider = '" . $orderarray ['provider'] . "', tracking = '" . $orderarray ['tracking'] 
				//. "', orderstatus = '" . $orderarray ['orderstatus'] . "' where accountid = '" . $orderarray ['accountid'] . "' and transactionid='" 
				//. $orderarray ['transactionid'] . "'";
				
				$trackinginfo['provider'] = 'WishPost';
				$trackinginfo['tracking'] = $value;
				$trackinginfo['orderstatus'] = ORDERSTATUS_APPLIEDTRACKING;
				$trackinginfo['accountid'] = $accountid;
				$trackinginfo['transactionid'] = $key;
				
				$this->dbhelper->updateOrder($trackinginfo);
			}
		}
	}
	
	public function getExpressInfo($userid){
		$expressInfo = array();
		$expressResult = $this->dbhelper->getExpressInfo($userid);
		while($expressAttr = mysql_fetch_array($expressResult)){
			$expressInfo[$expressAttr['express_attr_name']] = $expressAttr['express_attr_value'];
		}
		return $expressInfo;
	}
	
	public function getUserExpressInfos($userid,$iswe=0){
		$ExpressInfos = array();
		$userExpressInfos = $this->dbhelper->getExpressInfos($userid,$iswe);
		while($elabel = mysql_fetch_array($userExpressInfos)){
			$ExpressInfos[$elabel['product_id'].'|'.$elabel['countrycode']] = $elabel['express_id'].'|'.$elabel['express_name'];
		}
		return $ExpressInfos;
	}
	
	public  function getSubExpressInfos($parentExpressCode){
		$expressInfos = array();
		if($parentExpressCode == null){
			$expressInfosResult = $this->dbhelper->getSubExpressInfo();
		}else{
			$expressInfosResult = $this->dbhelper->getYanWenExpresses($parentExpressCode);
		}
			
		while($result = mysql_fetch_array($expressInfosResult)){
			$expressInfos[$result['express_id']] = $result['express_id'].'|'.$result['express_name'];
		}
		return $expressInfos;
	}
	
	public function getChildrenExpressinfosOF($parentExpressCode){
		$yanwenexpresses = array();
		$expresses = $this->dbhelper->getYanWenExpresses($parentExpressCode);
		while($exresult = mysql_fetch_array($expresses)){
			$yanwenexpresses[$exresult['express_id']] = $exresult['express_code'].'|'.$exresult['provider_name'];
		}
		return $yanwenexpresses;
	}
	
	public function getTrackingNumbersForLabel($userid,$provider){
		$numbers;
		$result = $this->dbhelper->getUserOrdersForLabels($userid);
		
		$curExpresses = $this->getChildrenExpressinfosOF($provider);

		$curExpressNames = array();
		foreach ($curExpresses as $key=>$value){
			$curExpressinfos = explode ( "|", $value );
			$curExpressName = $curExpressinfos[1];
			$curExpressNames[$curExpressName] = $curExpressName;
		}
		
		while($order = mysql_fetch_array($result)){
			if($order['tracking'] != null && $order['tracking']!= ''){
				if($curExpressNames[$order['provider']] != null)
					$numbers .= $order['tracking'].',';
			}
		}
		return $numbers;
	}
	
	public function getEUBOrders($userid){
		return $this->dbhelper->getEUBOrders($userid);
	}
	
	public function updateEUBOrders($orderid,$status){
		return $this->dbhelper->updateEUBOrderStatus($orderid, $status);
	}
	
	public function updateHasDownloadLabel($numbers){
		$trackings = explode(',',$numbers);
		$this->updateHasDownloadLabelForArray($trackings);
	}
	
	public function updateHasDownloadLabelForArray($trackings){
		foreach ($trackings as $tracking){
			if($tracking != null && $tracking != '')
				$this->dbhelper->updateOrderStatus($tracking, ORDERSTATUS_DOWNLOADEDLABEL);
		}
	}
	
	public function getProductVarsCount($productsVars){
		$productsInfo = array();
		$tempParentSKU = "";
		$varCounts = 0;
		$productsarray = array();
		$index = 0;
		while ( $curProductVar = mysql_fetch_array ( $productsVars) ) {
			$productsarray[$index++] = $curProductVar;
			
			$currentParentSKU =  $curProductVar['parent_sku'];
			
			if($currentParentSKU != $tempParentSKU ){
				
				
				if($tempParentSKU != ""){
					$productsInfo[$tempParentSKU] = $varCounts;
				}
				
				$tempParentSKU = $currentParentSKU;
				$varCounts = 0;
			}
			
			$varCounts ++;
		}
		
		//for last product:
		if($tempParentSKU != ""){
			$productsInfo[$tempParentSKU] = $varCounts;
		}
		
		$productsInfo['productvars'] = $productsarray;
		return $productsInfo;
	}
	
	public  function getProductDetails($productDetails){
		$productsInfo = array();
		$productVarsarray = array();
		$index = 0;
		while($curProductDetail = mysql_fetch_array($productDetails)){
			$productVarsarray[$index++] = $curProductDetail;
		}
		if($index>0){
			$productsInfo['parent_sku'] =  $productVarsarray[0]['parent_sku'];
			$productsInfo['id'] =  $productVarsarray[0]['id'];
			$productsInfo['main_image'] =  $productVarsarray[0]['main_image'];
			$productsInfo['extra_images'] =  $productVarsarray[0]['extra_images'];
			$productsInfo['is_promoted'] =  $productVarsarray[0]['is_promoted'];
			$productsInfo['name'] =  $productVarsarray[0]['name'];
			$productsInfo['review_status'] =  $productVarsarray[0]['review_status'];
			$productsInfo['tags'] =  $productVarsarray[0]['tags'];
			$productsInfo['description'] =  $productVarsarray[0]['description'];
			$productsInfo['number_saves'] =  $productVarsarray[0]['number_saves'];
			$productsInfo['number_sold'] =  $productVarsarray[0]['number_sold'];
			$productsInfo['date_uploaded'] =  $productVarsarray[0]['date_uploaded'];
			$productsInfo['date_updated'] =  $productVarsarray[0]['date_updated'];
			
			$productsInfo['productvars'] = $productVarsarray;
		}
		return $productsInfo;
	}
	
	
	public  function getProductVars($productid){
		$skus = array();
		$vars = $this->dbhelper->getProductVars($productid);
		while($var = mysql_fetch_array($vars)){
			$skus[] = $var['sku'];
		}
		return $skus;
	}
	
	public function getProductOrders($accountid,$productid,$startdate,$enddate){
		$orders = array();
		$productOrders = $this->dbhelper->getProductOrders($accountid, $productid, $startdate, $enddate);
		while($curOrder = mysql_fetch_array($productOrders)){
				$orders[] = $curOrder['orders'];
		}
		return $orders;
	}
	
	public function processLittleImpressionsProducts($accountid,$startdate,$enddate,$regularImpressions){
		$processResult = array();
		$products = array();
		$disabledsku = "";
		$lowerpricesku = "";
		$productImpressions = $this->dbhelper->getLittleImpressionsTrend($accountid, $startdate, $enddate, $regularImpressions);
		$preProductid;
		$preImpressions = 0;
		$isIncreased = 1;
		$datacount = 0;
		$totalImpressions = 0;
		while($productImpression = mysql_fetch_array($productImpressions)){
			$currentProductid = $productImpression['productid'];
			$currentImpressions = $productImpression['productimpressions'];
			
			if(strcmp($currentProductid,$preProductid) == 0){
				$datacount ++;
				$totalImpressions += $currentImpressions;
				if( $isIncreased == 1){
					if($currentImpressions>$preImpressions){//不增长
						$isIncreased = 0;
						$preImpressions = 0;
					}else{//继续增长
						$preImpressions = $currentImpressions;
					}	
				}else{//不增长的产品
					$preImpressions = $currentImpressions;
				}
			}else{
				if($isIncreased == 0){//不增长的产品,如果没加黄钻，并且上传时间超过3个月,则直接下架;
					$result = $this->dbhelper->getSKUUploadMoreThanDays($preProductid);
					if($p = mysql_fetch_array($result)){
						$curSKU = $p['parent_sku'];
						$is_promoted = $p['is_promoted'];
						if(strcmp($is_promoted,'False') == 0){
							$disabledsku .= $curSKU."  ,  ";
							$this->dbhelper->insertOptimizeJob($accountid, DISABLEPRODUCT, $preProductid, $enddate);
							//$client->disableProductById($preProductid);
						}else{
							$products[] = $preProductid;
						}
					}
				}
				
				if($isIncreased == 1 && isset($preProductid)){
					if( $datacount > 1 && $totalImpressions > 10){
						$products[] = $preProductid;
					}else{
						$this->dbhelper->insertOptimizeJob($accountid, LOWERSHIPPING, $preProductid, $enddate);
						$lowerpricesku .= $preProductid."   ,  ";
						//自动优化，运费减0.01
						/* $skus = $this->getProductVars($preProductid);
						foreach($skus as $sku){
							 $productVar = $client->getProductVariationBySKU($sku);
							 echo "<br/>SKU:".$sku." price:". $productVar->price;
							$params = array();
							$params['sku'] = $sku;
							
							$price = $productVar->price;
							$params['price'] = $price - 0.01; 

							$lowerpricesku .= $sku."   ,  ";
							//$client->updateProductVarByParams($params);
						} */
					}
 				}
				$preProductid = $currentProductid;
				$preImpressions = $currentImpressions;
				$isIncreased = 1;
				$datacount = 1;
				$totalImpressions = $currentImpressions;
			}
		}
		$processResult['productids'] = $products;
		$processResult['disable'] = $disabledsku;
		$processResult['lower'] = $lowerpricesku;
		
		return  $processResult;
	}
	
	public function isProductExist($productid){
		$result = $this->dbhelper->isProductExist($productid);
		if($curproduct = mysql_fetch_array($result)){
			if($curproduct['id'] != null)
				return true;
		}
		return false;
	}
	
	public function isProductVarExist($productvarid){
		$result = $this->dbhelper->isProductVarExist($productvarid);
		if($curproduct = mysql_fetch_array($result)){
			if($curproduct['id'] != null)
				return true;
		}
		return false;
	}
	
	public function insertOnlineProduct($currentProduct){
		if($this->isProductExist($currentProduct['id'])){
			$this->dbhelper->updateOnlineProduct($currentProduct);
		}else{
			$this->dbhelper->insertOnlineProduct($currentProduct);
		}
	}
	
	public function insertOnlineProductVar($currentProductVar){
		if($this->isProductVarExist($currentProductVar['id'])){
			$this->dbhelper->updateOnlineProductVar($currentProductVar);
		}else{
			$this->dbhelper->insertOnlineProductVar($currentProductVar);
		}
	}
	
	public function getCountrynames(){
		$countries = array();
		$cresult = $this->dbhelper->getCountrycode();
		while($country = mysql_fetch_array($cresult)){
			$countries[$country['code']] = $country['name'];
		}
		return $countries;
	}
	
	public function getChineseCountrynames(){
		$chcountries = array();
		$cresult = $this->dbhelper->getCountrycode();
		while($chcountry = mysql_fetch_array($cresult)){
			$chcountries[$chcountry['code']] = $chcountry['chinesename'];
		}
		return $chcountries;
	}
	
	public function getProductSKUCost($productsku,$accountid) {
		$productscost = array();
		$costresult = $this->dbhelper->getProductSKUCost($productsku, $accountid);
		while($productcostarr = mysql_fetch_array($costresult)){
			$productscost[$productcostarr['sku']] = $productcostarr['cost']; 
		}
		return $productscost;
	}
	
	public function getInventories($userid,$parentsku= null){
		$productsinventory = array();
		
		$inventoryresult = $this->dbhelper->getProductsInventory($userid,$parentsku);
		while($currinventory = mysql_fetch_array($inventoryresult)){
			$key = md5($currinventory['parentSKU']);
			
			$inventoryvalues = $productsinventory[$key];
			if($inventoryvalues == null){
				$inventoryvalues = array();
			}
			
			$currProductinventory = new productinventory();
			$currProductinventory->parentsku = $currinventory['parentSKU'];
			$currProductinventory->sku = $currinventory['SKU'];
			$currProductinventory->note = $currinventory['note'];
			$currProductinventory->inventory = $currinventory['inventory'];
			
			$inventoryvalues[] = $currProductinventory;
			/* 
			$skuinventorys = $inventoryvalues['SKUInventory'];
			if($skuinventorys == null){
				$skuinventorys = array();
			}
			
			$SKUValue = array();
			$SKUValue['SKU'] = $currinventory['SKU'];
			$SKUValue['INVENTORY'] = $currinventory['inventory'];
			$SKUValue['NOTE'] = $currinventory['note'];
			
			//$skuinventorys[$currinventory['SKU']] = $currinventory['inventory'];
			$skuinventorys[md5($currinventory['SKU'])] = $SKUValue;
			$inventoryvalues['SKUInventory'] = $skuinventorys; */
			
			
			$productsinventory[$key] = $inventoryvalues;
		}
		
		return $productsinventory;
	}
	
	public function getProductSKUs($userid,$parentSKU){
		$SKUs = array();
		$SKUsResult = $this->dbhelper->getProductSKUs($accountid, $parentsku);
		while($curSKU = mysql_fetch_array($SKUsResult)){
			$SKUs[] = $curSKU['sku'];
		}
		return $SKUs;
	}
	
	/*
	 * $operator:   0-出库； 1-入库.
	 * */
	public function updateproductInventory($accountid,$SKU,$quantity,$operator,$note){
		$parentSKU = null;
		$presult = $this->dbhelper->getProductIDByVSKU($accountid, $SKU); 
		if($pidresult = mysql_fetch_array($presult)){
			$pid = $pidresult['product_id'];
			if($pid != null){
				$pskuresult = $this->dbhelper->getProductSKUByID($pid);
				if($psku = mysql_fetch_array($pskuresult)){
					$parentSKU = $psku['parent_sku'];
				}
			}
		}
		if($parentSKU != null){
			$uidresult = $this->dbhelper->getUserid($accountid);
			if($useridresult = mysql_fetch_array($uidresult)){
				$userid = $useridresult['userid'];
				if($userid != null){
					$this->dbhelper->updateinventory($userid, $parentSKU, $SKU, $operator, $quantity);
					$this->dbhelper->inventoryoperaterecord($userid, $parentSKU, $SKU, $operator, $quantity, $note);
				}else{
					echo "*************failed to get userid of account:".$accountid.'"*****************';
				}
			}
		}else{
			echo "*************failed to get ParentSKU of SKU:".$SKU.'"*****************';
		}
	}
	
	public function getWEOrdercode($orderid){
		$rs = $this->dbhelper->getweordercodebyid($orderid);
		if($rsvalue = mysql_fetch_array($rs)){
			$ordercode = $rsvalue['weordercode'];
			return $ordercode;
		}
		return null;
	}
	
	public function getWEProducts(){
		$weproducts = array();
		$prs = $this->dbhelper->getWEProducts();
		while($curp = mysql_fetch_array($prs)){
			$weproducts[] = $curp;
		}
		return $weproducts;
	}
	
	public function getTrackingsFromDay($sinceday){
		$trackings = array();
		$trs = $this->dbhelper->getTrackingsFromDay($sinceday);
		while($curs = mysql_fetch_array($trs)){
			$trackings[] = $curs['tracking'];
		}
		return $trackings;
	}
	
	private function getTempSKU($parentSKU){
		$tempParSKU = "";
		$spindex = strpos($parentSKU,'(');
		if($spindex === false){
			$tempParSKU = $parentSKU;
		}else{
			$tempParSKU = substr_replace($parentSKU,'',$spindex);
		}
		
		return $tempParSKU;
	}
	
	private function getTempsize($currentsize){
		$tempSize = "";
		$ssindex = strpos($currentsize,'(');
		if($ssindex === false){
			$tempSize = $currentsize;
		}else{
			$tempSize = substr_replace($currentsize,'',$ssindex);
		}
		return $tempSize;
	}
}
