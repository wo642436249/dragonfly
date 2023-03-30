<?php

namespace app\index\model;

use app\common\resource\Redis;
use think\Model;
use think\Log;

class Refundvoucher extends Model
{
    protected $table = "refund_voucher";
    protected $auto = ['status'];

    public function refundVoucherList($params)
    {
        $where = [];
        foreach ($params as $key => $value) {
            if ($value == '') continue;
            switch ($key) {
                case 'search_orderid':
                    $where['tkid'] = $value;
                    break;
                case 'search_voucher_time': // 凭证日期
                    $value_list = explode(" - ", $value);
                    $where['voucher_time'] = ['BETWEEN', [strtotime($value_list[0] . " 00:00:00"), strtotime($value_list[1] . " 23:59:59")]];
                    break;
                case 'search_voucher_ctime': // 凭证创建日期
                    $value_list = explode(" - ", $value);
                    $where['create_time'] = ['BETWEEN', [strtotime($value_list[0] . " 00:00:00"), strtotime($value_list[1] . " 23:59:59")]];
                    break;
                case 'search_refund_status': // 凭证状态
                    $where['status'] = $value;
                    break;
                default:
                    break;
            }
        }
        $refund_voucher_res_arr = [];
        $refund_details_res_arr = [];
        $refund_voucher_count = $this->where($where)->group('tkid,type')->count();
        $refund_voucher_res = $this->where($where)->page($params['page'], $params['limit'])
            ->group('tkid,type')->order(['create_time' => 'desc'])->select();
        foreach ($refund_voucher_res as $valrv) {
            $refund_voucher_res_arr[] = $valrv['tkid'];
        }
        $refund_details_map['tkid'] = ['in', $refund_voucher_res_arr];
        $refundDetailsModle = new RefundDetails();
        $refund_details_res = $refundDetailsModle->field('tkid, ROUND(sum(refund_money),2) as refund_money')->where($refund_details_map)->group('tkid')->order(['create_time' => 'desc'])->select();
        foreach ($refund_details_res as $valrd) {
            $refund_details_res_arr[$valrd['tkid']] = $valrd['refund_money'];
        }
        foreach ($refund_voucher_res as $valrv) {
            $valrv['refund_money'] = $refund_details_res_arr[$valrv['tkid']];
        }
        return ['refund_voucher_res' => $refund_voucher_res, 'refund_voucher_count' => $refund_voucher_count];
    }

    /**
     * @Function NotOpenVoucher
     * @Notes: 未开通退款凭证
     * @author: 刘子鹤
     * @CreateTime: 2019/5/24
     * @remark: abstract(摘要)， subjects (科目), borrow_amount (借方金额), loan_amount (贷方金额), cash_flow (现金流指定)
     */
    public function notOpenVoucher($tkid)
    {
        $refund_map['tkid'] = $tkid;
        $bankModel = new Bank();
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        foreach ($refund_details_sel_obj as $val) {
            $refund_details_find_obj = $val->getData();
            $abstract = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
            $bank_map['payType'] = $refund_find_obj['payType'];
            $bank_find_obj = $bankModel->where($bank_map)->find();
            // 分录一
            $entries_one_arr = $this->entriesCreate($abstract, '220303', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
            // 分录二
            $cash_flow = '';
            if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                $cash_flow = '01';
            }
            $entries_two_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
            $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, false);
            $insert_field_arr['bumen'] = '';
            $insert_field_arr['xm'] = '';
            $inert_arr = [
                array_merge($entries_one_arr, $insert_field_arr)
                , array_merge($entries_two_arr, $insert_field_arr)
            ];
            $this->insertAll($inert_arr);
        }
    }

    /**
     * @Function openVoucher
     * @Notes: 开通后未做收入确认退款凭证  【 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时 】
     * @author: 刘子鹤
     * @CreateTime: 2019/5/24
     * @remark: abstract(摘要)， subjects (科目), borrow_amount (借方金额), loan_amount (贷方金额), cash_flow (现金流指定)
     */
    public function openNoConfirmVoucher($tkid, $orderVoucherStatusData, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refund_map['is_kt'] = 1;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }
                $cr_time = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '费用';
                $abstract_two = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                $abstract_three = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();

                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';

                if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                    $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                    $entries_one_arr = $this->entriesCreate($abstract, '220304', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                    $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                    $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                    $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                    $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                $km_four = $bank_find_obj['subNum'];
                if ($is_last) {
                    $km_four = '224111';
                }

                $cash_flow = '';
                if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                    $cash_flow = '01';
                }
                $entries_four_arr = $this->entriesCreate($abstract, $km_four, $bank_find_obj['accountNum'], $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                $this->insertAll($inert_arr);
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }
                $cr_time = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();

                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';

                $entries_arr = $this->entriesCreate($abstract, '220304', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                $km_four = $bank_find_obj['subNum'];
                if ($is_last) {
                    $km_four = '224111';
                }

                $cash_flow = '';
                if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                    $cash_flow = '01';
                }

                $entries_four_arr = $this->entriesCreate($abstract, $km_four, $bank_find_obj['accountNum'], $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);
                $this->insertAll($inert_arr);
            }
        }
    }

    /**
     * @Notes:  已开通未确认收入退款凭证 -- 订单所属部门账套和退款方式所属账套不同
     */
    public function openNoConfirmVoucherDifferent($tkid, $orderVoucherStatusData, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $zordersModel = new Zorders();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $zorders_find_obj = $zordersModel->where(['orderid' => $refund_find_obj['orderid']])->find();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        $account = $zorders_find_obj['u8_account'] ?? '';
        if ($account == '') {
            $departmentModel = new Depart();
            $depaccount_map['id'] = $refund_find_obj['bumen'];
            $department_find_obj = $departmentModel->where($depaccount_map)->find();
            $account = $department_find_obj['account'] ?? '';
        }
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                    switch ($account) {
                        case '002':
                            $abstract_four = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_five = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_five = '12210501';
                            break;
                        default:
                            $abstract_four = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_five = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_five = '12210508';
                    }
                    $km_four = '224106';

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                        $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                        $entries_arr = $this->entriesCreate($abstract, '220304', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        if ($is_last) {
                            $km_four = '224111';
                        }
                        $entries_two_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                    }

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_five, $km_five, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                    $abstract_four = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_five = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $entries_one_arr = $this->entriesCreate($abstract, '220304', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $sub_four = '224106';
                    if ($is_last) {
                        $sub_four = '224111';
                    }
                    $entries_one_arr = $this->entriesCreate($abstract_four, $sub_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_four, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                    $abstract_five = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210501';
                            break;
                        default:
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210508';
                    }

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $entries_one_arr = $this->entriesCreate($abstract, '220304', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        if ($is_last) {
                            $km_four = '224111';
                        }

                        $cash_flow = '';
                        if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_two_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                    }

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_five, '224106', $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                }
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_two = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        $entries_arr = $this->entriesCreate($abstract, '220304', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $last_sub = '224106';
                        if ($is_last) {
                            $last_sub = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $last_sub, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        if (!$is_last) {
                            $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);


                            $cash_flow = '';
                            if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                                $cash_flow = '01';
                            }

                            $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        }
                    }
                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_two = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_three = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        $entries_arr = $this->entriesCreate($abstract, '220304', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $sub_two = '224106';
                        if ($is_last) {
                            $sub_two = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $sub_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        if (!$is_last) {
                            $entries_arr = $this->entriesCreate($abstract_two, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                            $cash_flow = '';
                            if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                                $cash_flow = '01';
                            }

                            $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                            $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                            $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        }
                    }
                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_two = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210508';
                    }
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        $entries_arr = $this->entriesCreate($abstract, '220304', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        if ($is_last) {
                            $km_two = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        if (!$is_last) {
                            $entries_arr = $this->entriesCreate($abstract_two, '224106', $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                            $cash_flow = '';
                            if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                                $cash_flow = '01';
                            }

                            $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                            $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        }
                    }
                    $this->insertAll($inert_arr);
                }
            }
        }
    }

    /**
     * @Function openVoucher
     * @Notes: 开通后已做收入确认退款凭证  【 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时 】
     * @author: 刘子鹤
     * @CreateTime: 2019/5/24
     * @remark: abstract(摘要)， subjects (科目), borrow_amount (借方金额), loan_amount (贷方金额), cash_flow (现金流指定)
     */
    public function openConfirmVoucher($tkid, $orderVoucherStatusData, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refund_map['is_kt'] = 1;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_old_arr = $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }
                $cr_time = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();

                if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                    $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                    $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }
                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';

                if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                    $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                    $entries_one_arr = $this->entriesCreate($abstract, '600103', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                    $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                    $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                }

                if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                    if (in_array($refund_find_obj['pid'], [157, 158, 159, 233])) { // 软件类产品
                        $loan_amount = round($refund_find_obj['refund_money'] * 0.3 / 1.06 * 0.06, 2);
                    } else {
                        $loan_amount = round($refund_find_obj['refund_money'] / 1.06 * 0.06, 2);
                    }
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                    $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                $km_two = $bank_find_obj['subNum'];
                if ($is_last) {
                    $km_two = '224111';
                }

                $cash_flow = '';
                if (strpos($km_two, '1001') !== false || strpos($km_two, '1002') !== false || strpos($km_two, '1012') !== false) {
                    $cash_flow = '01';
                }

                $entries_four_arr = $this->entriesCreate($abstract, $km_two, $bank_find_obj['accountNum'], $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                $this->insertAll($inert_arr);
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_old_arr = $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }
                $cr_time = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();

                if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                    $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                    $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }
                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';

                if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                    $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                    $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                    $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                    $entries_one_arr = $this->entriesCreate($abstract, '600103', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                }

                if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0) {
                    $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                    $loan_amount = round($refund_details_find_obj['refund_service_money_get_confirm'] / 1.06 * 0.06, 2);
                    $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }

                $km_two = $bank_find_obj['subNum'];
                if ($is_last) {
                    $km_two = '224111';
                }

                $cash_flow = '';
                if (strpos($km_two, '1001') !== false || strpos($km_two, '1002') !== false || strpos($km_two, '1012') !== false) {
                    $cash_flow = '01';
                }

                $entries_four_arr = $this->entriesCreate($abstract, $km_two, $bank_find_obj['accountNum'], $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                $this->insertAll($inert_arr);
            }
        }
    }

    /**
     * @Notes:  已开通已确认收入退款凭证 -- 订单所属部门账套和退款方式所属账套不同
     */
    public function openConfirmVoucherDifferent($tkid, $orderVoucherStatusData, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $zordersModel = new Zorders();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $zorders_find_obj = $zordersModel->where(['orderid' => $refund_find_obj['orderid']])->find();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        $account = $zorders_find_obj['u8_account'] ?? '';
        if ($account == '') {
            $departmentModel = new Depart();
            $depaccount_map['id'] = $refund_find_obj['bumen'];
            $department_find_obj = $departmentModel->where($depaccount_map)->find();
            $account = $department_find_obj['account'] ?? '';
        }
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_old_arr = $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $cr_time = $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_two = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        if (in_array($refund_find_obj['pid'], [157, 158, 159, 233])) { // 软件类产品
                            $loan_amount = round($refund_find_obj['refund_money'] * 0.3 / 1.06 * 0.06, 2);
                        } else {
                            $loan_amount = round($refund_find_obj['refund_money'] / 1.06 * 0.06, 2);
                        }
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $km_two = '224106';
                    if ($is_last) {
                        $km_two = '224111';
                    }

                    $cash_flow = '';
                    if (strpos($km_two, '1001') !== false || strpos($km_two, '1002') !== false || strpos($km_two, '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_four_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif (($bank_find_obj->accountNum == '014' && $account == '002') || ($bank_find_obj->accountNum == '002' && $account == '014')) {
                    if ($account == '002') {
                        $abstract_four = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                        $abstract_five = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    } else {
                        $abstract_four = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                        $abstract_five = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    }
                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        if (in_array($refund_find_obj['pid'], [157, 158, 159, 233])) { // 软件类产品
                            $loan_amount = round($refund_find_obj['refund_money'] * 0.3 / 1.06 * 0.06, 2);
                        } else {
                            $loan_amount = round($refund_find_obj['refund_money'] / 1.06 * 0.06, 2);
                        }
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $km_two = '224106';
                    if ($is_last) {
                        $km_two = '224111';
                    }

                    $entries_one_arr = $this->entriesCreate($abstract_four, $km_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_four, '224106', $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    $abstract_five = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_four = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210501';
                            break;
                        default:
                            $abstract_four = $crTime . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210508';
                    }

                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        if (in_array($refund_find_obj['pid'], [157, 158, 159, 233])) { // 软件类产品
                            $loan_amount = round($refund_find_obj['refund_money'] * 0.3 / 1.06 * 0.06, 2);
                        } else {
                            $loan_amount = round($refund_find_obj['refund_money'] / 1.06 * 0.06, 2);
                        }
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($is_last) {
                        $km_four = '224111';
                    }

                    $entries_one_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_five, '224106', $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                }
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_old_arr = $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $cr_time = $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_two = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        // $loan_amount = bcsub($refund_details_find_obj['refund_service_money_get_confirm'], $refund_details_find_obj['refund_service_money_get_confirm_free'], 2);
                        $loan_amount = round($refund_details_find_obj['refund_service_money_get_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $km_two = '224106';
                    if ($is_last) {
                        $km_two = '224111';
                    }

                    $cash_flow = '';
                    if (strpos($km_two, '1001') !== false || strpos($km_two, '1002') !== false || strpos($km_two, '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_four_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif (($bank_find_obj->accountNum == '014' && $account == '002') || ($bank_find_obj->accountNum == '002' && $account == '014')) {
                    if ($account == '002') {
                        $abstract_two = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                        $abstract_three = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    } else {
                        $abstract_two = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                        $abstract_three = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    }
                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        //$loan_amount = round(bcadd($refund_details_find_obj['refund_service_money_get_confirm'], $refund_details_find_obj['refund_service_money_get_confirm_free'], 2) / 1.06 * 0.06, 2);
                        $loan_amount = round($refund_details_find_obj['refund_service_money_get_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $km_two = '224106';
                    if ($is_last) {
                        $km_two = '224111';
                    }

                    $cash_flow = '';
                    if (strpos($km_two, '1001') !== false || strpos($km_two, '1002') !== false || strpos($km_two, '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_four_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_two, '224106', $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_two = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = $km_two = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = $km_two = '12210508';
                    }

                    if ($refund_details_find_obj['refund_soft_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件费';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '600105', $account, $refund_details_find_obj['refund_soft_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                        $loan_amount = bcsub($refund_details_find_obj['refund_soft_money_get_confirm'], $refund_details_find_obj['refund_soft_money_get_confirm_free'], 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '剩余未分摊费用';
                        $entries_one_arr = $this->entriesCreate($this_abstract, '220304', $account, $refund_details_find_obj['refund_service_money_not_confirm'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm_free'] > 0) {
                        $insert_field_arr['bumen'] = $insert_field_old_arr['bumen'];
                        $insert_field_arr['xm'] = $insert_field_old_arr['xm'];
                        $entries_one_arr = $this->entriesCreate($abstract, '600103', $account, $refund_details_find_obj['refund_service_money_get_confirm_free'] * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                    }

                    if ($refund_details_find_obj['refund_service_money_get_confirm'] > 0) {
                        $this_abstract = $cr_time . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                        $loan_amount = round($refund_details_find_obj['refund_service_money_get_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($this_abstract, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }


                    if ($is_last) {
                        $km_four = '224111';
                    }

                    $cash_flow = '';
                    if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_four_arr = $this->entriesCreate($abstract_two, $km_four, $account, $refund_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_four_arr, $insert_field_arr);

                    if (!$is_last) {
                        $entries_arr = $this->entriesCreate($abstract_two, $km_two, $bank_find_obj->accountNum, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }

                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj->accountNum, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    $this->insertAll($inert_arr);
                }
            }
        }
    }


    /**
     * @Notes: 预开通退款凭证  【 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时 】
     * @remark: abstract(摘要)， subjects (科目), borrow_amount (借方金额), loan_amount (贷方金额), cash_flow (现金流指定)
     */
    public function afterOpenVoucher($tkid, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refund_map['is_kt'] = 2;
        $zordersModel = new Zorders();
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $orderVoucherStatusModel = new OrderVoucherStatus();
        $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
        $zorders = $zordersModel->where('orderid', $refund_find_obj['orderid'])->find();
        $zordersData = $zorders->getData();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj);
                $abstract = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
                $abstract_two = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                $abstract_three = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                    $bank_find_obj['subNum'] = '224111';
                }
                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';
                // 分录一
                if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                    $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                    if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                        $entries_one_arr = $this->entriesCreate($abstract, '220303', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    } else {
                        $entries_one_arr = $this->entriesCreate($abstract, '220305', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    }
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }
                // 分录二
                if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                    $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                    $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }
                // 分录三
                if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                    $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                    $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $bank_find_obj['accountNum'], $loan_amount * -1, '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                }
                // 分录四
                if ($refund_details_find_obj['refund_money'] > 0) {
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_two_arr = $this->entriesCreate($abstract_four, $bank_find_obj['subNum'], $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                }
                $this->insertAll($inert_arr);
            }
        } else { // 未开发票
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj);
                $abstract = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }
                if ($refund_details_find_obj['refund_money'] > 0) {
                    // 分录一
                    if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                        $entries_one_arr = $this->entriesCreate($abstract, '220303', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                    } else {
                        $entries_one_arr = $this->entriesCreate($abstract, '220305', $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                    }
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    // 分录二
                    if ($is_last) {
                        $bank_find_obj['subNum'] = '224111';
                    }
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_two_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                    $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                }
                $this->insertAll($inert_arr);
            }
        }
    }

    /**
     * @Notes: 预开通退款过渡凭证  【 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时 】
     * @remark: abstract(摘要)， subjects (科目), borrow_amount (借方金额), loan_amount (贷方金额), cash_flow (现金流指定)
     */
    public function afterOpenInterimVoucher($tkid)
    {
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $orderVoucherStatusModel = new OrderVoucherStatus();
        $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, false);
                $abstract = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();
                $insert_field_arr['type'] = 2;

                $entries_one_arr = $this->entriesCreate($abstract, '224111', $bank_find_obj['accountNum'], '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                $cash_flow = '';
                if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                    $cash_flow = '01';
                }

                $entries_two_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);

                $this->insertAll($inert_arr);
            }
        } else { // 未开发票
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, false);
                $abstract = date("Y-m-d", $refund_details_find_obj['create_time']) . '退客户' . $refund_find_obj['khname'] . '服务费';
                $bank_map['payType'] = $refund_find_obj['payType'];
                $bank_find_obj = $bankModel->where($bank_map)->find();
                $insert_field_arr['type'] = 2;

                $entries_one_arr = $this->entriesCreate($abstract, '224111', $bank_find_obj['accountNum'], '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                $insert_field_arr['bumen'] = '';
                $insert_field_arr['xm'] = '';
                $cash_flow = '';
                if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                    $cash_flow = '01';
                }

                $entries_two_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], $bank_find_obj['accountNum'], $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);

                $this->insertAll($inert_arr);
            }
        }
    }

    /**
     * @Notes:  预开通退款凭证 -- 订单所属部门账套和退款方式所属账套不同
     */
    public function afterOpenVoucherDifferent($tkid, $is_last = false)
    {
        $refund_map['tkid'] = $tkid;
        $refund_map['is_kt'] = 2;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $zordersModel = new Zorders();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $zorders_find_obj = $zordersModel->where(['orderid' => $refund_find_obj['orderid']])->find();
        $orderVoucherStatusModel = new OrderVoucherStatus();
        $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
        $zordersData = $zorders_find_obj->getData();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        $account = $zorders_find_obj['u8_account'] ?? '';
        if ($account == '') {
            $departmentModel = new Depart();
            $depaccount_map['id'] = $refund_find_obj['bumen'];
            $department_find_obj = $departmentModel->where($depaccount_map)->find();
            $account = $department_find_obj['account'] ?? '';
        }
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                    $abstract_four = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    switch ($account) {
                        case '002':
                            $abstract_five = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_five = '12210501';
                            break;
                        default:
                            $abstract_five = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_five = '12210508';
                    }
                    $km_four = '224106';

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                        $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_arr = $this->entriesCreate($abstract, '220303', $account, $loan_amount * -1, '', '', $val['orderid']);
                        } else {
                            $entries_arr = $this->entriesCreate($abstract, '220305', $account, $loan_amount * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        if ($is_last) {
                            $km_four = '224111';
                        }
                        $entries_two_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                    }

                    $entries_arr = $this->entriesCreate($abstract_five, $km_five, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                    $abstract_four = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_five = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                        $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_one_arr = $this->entriesCreate($abstract, '220303', $account, $loan_amount * -1, '', '', $val['orderid']);
                        } else {
                            $entries_one_arr = $this->entriesCreate($abstract, '220305', $account, $loan_amount * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $sub_four = '224106';
                    if ($is_last) {
                        $sub_four = '224111';
                    }
                    $entries_one_arr = $this->entriesCreate($abstract_four, $sub_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_four, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '002' && $account == '014') {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';//'日期退客户客户姓名服务费销项税'
                    $abstract_four = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_five = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                        $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_one_arr = $this->entriesCreate($abstract, '220303', $account, $loan_amount * -1, '', '', $val['orderid']);
                        } else {
                            $entries_one_arr = $this->entriesCreate($abstract, '220305', $account, $loan_amount * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    $sub_four = '224106';
                    if ($is_last) {
                        $sub_four = '224111';
                    }
                    $entries_one_arr = $this->entriesCreate($abstract_four, $sub_four, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_four, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    $abstract_two = $crTime . '退客户' . $refund_find_obj['khname'] . '软件销项税';
                    $abstract_three = $crTime . '退客户' . $refund_find_obj['khname'] . '服务销项税';
                    $abstract_five = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210501';
                            break;
                        default:
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210508';
                    }

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0 || $refund_details_find_obj['refund_service_money_not_confirm']) {
                        $loan_amount = bcadd($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13, $refund_details_find_obj['refund_service_money_not_confirm'] / 1.06, 2);
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_one_arr = $this->entriesCreate($abstract, '220303', $account, $loan_amount * -1, '', '', $val['orderid']);
                        } else {
                            $entries_one_arr = $this->entriesCreate($abstract, '220305', $account, $loan_amount * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_soft_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_soft_money_not_confirm'] / 1.13 * 0.13, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_two, '2221010201', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_service_money_not_confirm'] > 0) {
                        $loan_amount = round($refund_details_find_obj['refund_service_money_not_confirm'] / 1.06 * 0.06, 2);
                        $entries_one_arr = $this->entriesCreate($abstract_three, '2221010202', $account, $loan_amount * -1, '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        if ($is_last) {
                            $km_four = '224111';
                        }
                        $cash_flow = '';
                        if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_two_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);
                    }

                    $entries_arr = $this->entriesCreate($abstract_five, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                }
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, !$is_last);
                $bank_map['payType'] = $refund_find_obj['payType'];
                if ($is_last) {
                    $insert_field_arr['type'] = 3;
                }

                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_two = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_arr = $this->entriesCreate($abstract, '220303', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        } else {
                            $entries_arr = $this->entriesCreate($abstract, '220305', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $last_sub = '224106';
                        if ($is_last) {
                            $last_sub = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $last_sub, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_two = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_three = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_arr = $this->entriesCreate($abstract, '220303', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        } else {
                            $entries_arr = $this->entriesCreate($abstract, '220305', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $sub_two = '224106';
                        if ($is_last) {
                            $sub_two = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $sub_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '002' && $account == '014') {
                    $abstract_two = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_three = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_arr = $this->entriesCreate($abstract, '220303', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        } else {
                            $entries_arr = $this->entriesCreate($abstract, '220305', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $sub_two = '224106';
                        if ($is_last) {
                            $sub_two = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $sub_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_two = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210508';
                    }
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        if ($zordersData['create_time'] < strtotime('2023-01-01')) {
                            $entries_arr = $this->entriesCreate($abstract, '220303', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        } else {
                            $entries_arr = $this->entriesCreate($abstract, '220305', $account, $refund_details_find_obj['refund_money'] * -1, '', '', $val['orderid']);
                        }
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        if ($is_last) {
                            $km_two = '224111';
                        }
                        $entries_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                }
            }
        }
    }

    /**
     * @Notes:  预开通退款过渡凭证 -- 订单所属部门账套和退款方式所属账套不同
     */
    public function afterOpenInterimVoucherDifferent($tkid)
    {
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refundDetailsModle = new RefundDetails();
        $zordersModel = new Zorders();
        $bankModel = new Bank();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $refund_details_sel_obj = $refundDetailsModle->where($refund_map)->order('create_time', 'desc')->select();
        $zorders_find_obj = $zordersModel->where(['orderid' => $refund_find_obj['orderid']])->find();
        $orderVoucherStatusModel = new OrderVoucherStatus();
        $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
        $invoice_status = $orderVoucherStatusData['invoice_status'] ?? false;
        $account = $zorders_find_obj['u8_account'] ?? '';
        if ($account == '') {
            $departmentModel = new Depart();
            $depaccount_map['id'] = $refund_find_obj['bumen'];
            $department_find_obj = $departmentModel->where($depaccount_map)->find();
            $account = $department_find_obj['account'] ?? '';
        }
        if ($invoice_status == 1) {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, false);
                $bank_map['payType'] = $refund_find_obj['payType'];
                $insert_field_arr['type'] = 2;
                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_three = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_three = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    $entries_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                    $entries_arr = $this->entriesCreate($abstract, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_four = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_five = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';

                    $entries_one_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $entries_one_arr = $this->entriesCreate($abstract, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_four, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '002' && $account == '014') {
                    $abstract_four = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_five = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';

                    $entries_one_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $entries_one_arr = $this->entriesCreate($abstract, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_four, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    $abstract_five = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210501';
                            break;
                        default:
                            $abstract_four = date("Y-m-d", $refund_details_find_obj['create_time']) . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_four = '12210508';
                    }

                    $entries_one_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_one_arr, $insert_field_arr);

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';
                    $cash_flow = '';
                    if (strpos($km_four, '1001') !== false || strpos($km_four, '1002') !== false || strpos($km_four, '1012') !== false) {
                        $cash_flow = '01';
                    }

                    $entries_two_arr = $this->entriesCreate($abstract_four, $km_four, $account, $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_two_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_five, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $this->insertAll($inert_arr);
                }
            }
        } else {
            foreach ($refund_details_sel_obj as $val) {
                $inert_arr = [];
                $refund_details_find_obj = $val->getData();
                $insert_field_arr = $this->insertField($refund_find_obj, $refund_details_find_obj, false);
                $bank_map['payType'] = $refund_find_obj['payType'];
                $insert_field_arr['type'] = 2;
                $bank_find_obj = $bankModel->where($bank_map)->find();
                $crTime = date("Y-m-d", $refund_details_find_obj['create_time']);
                $abstract = $crTime . '退客户' . $refund_find_obj['khname'] . '服务费';
                if ($bank_find_obj->accountNum == '001' && in_array($account, ['002', '014'])) {
                    switch ($account) {
                        case '002':
                            $abstract_two = $crTime . '总部代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '总部代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $abstract_three = $crTime . '代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_three = '12210508';
                    }

                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $entries_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';
                        $entries_arr = $this->entriesCreate($abstract_two, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, $km_three, '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '001', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '014' && $account == '002') {
                    $abstract_two = $crTime . '桥西代石家庄退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_three = $crTime . '桥西代石分退客户' . $refund_find_obj['khname'] . '服务费';
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $entries_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', '014', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '014', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif ($bank_find_obj->accountNum == '002' && $account == '014') {
                    $abstract_two = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    $abstract_three = $crTime . '石家庄代桥西退客户' . $refund_find_obj['khname'] . '服务费';
                    if ($refund_details_find_obj['refund_money'] > 0) {
                        $entries_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $insert_field_arr['bumen'] = '';
                        $insert_field_arr['xm'] = '';

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_two, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                        $cash_flow = '';
                        if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                            $cash_flow = '01';
                        }
                        $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210501', '001', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                        $entries_arr = $this->entriesCreate($abstract_three, '12210508', '001', $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                        $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    }
                    $this->insertAll($inert_arr);
                } elseif (in_array($bank_find_obj->accountNum, ['014', '002']) && $account == '001') {
                    switch ($bank_find_obj->accountNum) {
                        case '002':
                            $abstract_two = $crTime . '石家庄代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210501';
                            break;
                        default:
                            $abstract_two = $crTime . '桥西代总部退客户' . $refund_find_obj['khname'] . '服务费';
                            $km_two = '12210508';
                    }

                    $entries_arr = $this->entriesCreate($abstract, '224111', $account, '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $insert_field_arr['bumen'] = '';
                    $insert_field_arr['xm'] = '';

                    $entries_arr = $this->entriesCreate($abstract_two, $km_two, $account, $refund_details_find_obj['refund_money'], '', '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);

                    $entries_arr = $this->entriesCreate($abstract_two, '224106', '002', '', $refund_details_find_obj['refund_money'], '', $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $cash_flow = '';
                    if (strpos($bank_find_obj['subNum'], '1001') !== false || strpos($bank_find_obj['subNum'], '1002') !== false || strpos($bank_find_obj['subNum'], '1012') !== false) {
                        $cash_flow = '01';
                    }
                    $entries_arr = $this->entriesCreate($abstract, $bank_find_obj['subNum'], '002', $refund_details_find_obj['refund_money'], '', $cash_flow, $val['orderid']);
                    $inert_arr[] = array_merge($entries_arr, $insert_field_arr);
                    $this->insertAll($inert_arr);
                }
            }
        }
    }

    /**
     * @Function entriesCreate
     * @Notes: 退款凭证 -- 分录一
     * @param $abstract_one
     * @param $subjects_one
     * @param $account_num_one
     * @param $loan_amount_one
     * @param string $borrow_amount_one
     * @param string $cash_flow_one
     * @return mixed
     * @author: 刘子鹤
     * @CreateTime: 2019/5/29
     * @remark:
     */
    public function entriesCreate($abstract_one, $subjects_one, $account_num_one, $loan_amount_one, $borrow_amount_one = '', $cash_flow_one = '', $orderid = '')
    {
        $entriesCreate['abstract'] = $abstract_one; // 摘要
        $entriesCreate['subjects'] = $subjects_one; // 科目
        $entriesCreate['account_num'] = $account_num_one; // 账套号
        $entriesCreate['loan_amount'] = $loan_amount_one; // 贷方金额
        $entriesCreate['borrow_amount'] = $borrow_amount_one; // 借方金额
        $entriesCreate['cash_flow'] = $cash_flow_one; // 现金流指定
        $entriesCreate['orderid'] = $orderid; // 订单号
        return $entriesCreate;
    }

    /**
     * @Function insertField
     * @Notes: 退款凭证字段
     * @param $refund_find_obj
     * @return array
     * @author: 刘子鹤
     * @CreateTime: 2019/5/29
     * @remark:
     */
    public function insertField($refund_find_obj, $refund_details_find_obj, $reload_voucher_time = true)
    {
        $now_time = time();
        $departModel = new Depart();
        $zorderModel = new Zorders();
        $productU8Model = new Productu8();
        $zorders_find_obj = $zorderModel->where(['orderid' => $refund_details_find_obj['orderid']])->find();
        $bm = $zorders_find_obj['u8_bm'] ?? '';
        if ($bm == '') {
            $depart_arr = $departModel->where(['id' => $refund_find_obj['bumen']])->find();
            $bm = $depart_arr['bm'];
        }
        $productu8_arr = $productU8Model->where(['pid' => $zorders_find_obj['pid']])->find();
        if ($reload_voucher_time) {
            $voucher_time = $now_time;
        } else {
            $voucher_time = $refund_details_find_obj['refund_time'] ?? $now_time;
        }
        return [
            'tkid' => $refund_find_obj['tkid']
            , 'is_kt' => $refund_details_find_obj['is_kt']
            , 'voucher_time' => $voucher_time
            , 'create_time' => $now_time
            , 'update_time' => $now_time
            , 'bumen' => $bm
            , 'xm' => $productu8_arr['xm'] ?? 0
            , 'refund_money' => $refund_details_find_obj['refund_money']
            , 'khname' => $refund_find_obj['khname']
        ];
    }

    /**
     * [getzuname 获取部门name]
     * @Author     刘子鹤
     * @CreateTime 2019-07-17
     * @UpdateTime 2019-07-17
     * @examples   [examples]
     * @param      [type]     $bumenid [description]
     * @return     [type]              [description]
     */
    public function getzuname($bumenid)
    {
        $redis_client = new \Predis\Client(config('clusterLink'), config('clusterOptions'));
        $depart_json = $redis_client->hget('depart', $bumenid);
        $depart_arr = json_decode($depart_json, true);
        return $depart_arr['name'];
    }

    /**
     * @Function refundDetails
     * @Notes: 退款账套明细 -- 凭证单据
     * @param $params
     * @return array|false|\PDOStatement|string|Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @author: 刘子鹤
     * @CreateTime: 2019/5/27
     * @remark:
     */
    public function refundDetails($params)
    {
        $where['tkid'] = $params['tkid'];
        $where['type'] = $params['type'];
        $refund_voucher_res = $this->distinct(true)->field('rv.*, ba.accountName as account_name')->where($where)
            ->alias('rv')
            ->join('bank ba', 'rv.account_num = ba.accountNum', 'LEFT')
            ->select();
        return $refund_voucher_res;
    }

    /**
     * @Function refundDetailsData
     * @Notes: 退款账套明细 -- 数据明细
     * @param $params
     * @author: 刘子鹤
     * @CreateTime: 2019/5/29
     * @remark:
     */
    public function refundDetailsData($params)
    {
        $refundDetailsModel = new RefundDetails();
        $where['tkid'] = $params['tkid'];
        $depaccount_find_arr = [];
        $depaccount_find_obj = $refundDetailsModel->where($where)->order('create_time', 'desc')->select();
        foreach ($depaccount_find_obj as $val) {
            $val['bumen'] = getzuname($val['bumen']);
            $depaccount_find_arr[] = $val;
        }
        return $depaccount_find_arr;
    }

    /**
     * [uptou8Callback description]
     * @Author           刘子鹤
     * @CreateTime       2019-06-06
     * @LatestUpdateTime 2019-06-06
     * @remark           [ $voucher_id,$account,$result ]
     * @param            [type]      $voucher_num [description]
     * @param            [type]      $account     [description]
     * @param            [type]      $voucher_id  [description]
     * @return           [type]                   [description]
     * @license          [license]
     * @version          [version]
     * @copyright        [copyright]
     */
    public function uptou8Callback($voucher_id, $account, $result)
    {
        $map['id'] = $voucher_id;
        $refund_voucher_find = $this->where($map)->find();
        $where['tkid'] = $refund_voucher_find['tkid'];
        $where['account_num'] = $account;
        if (isset($result['is_success'])) {
            // 有 is_success 这个字段，说明上传失败
            $up_data = [
                'voucher_num' => $result['voucher_num'],
                'status' => '5',
            ];
        } else {
            $up_data = [
                'voucher_num' => $result['voucher_num'],
                'status' => '2',
            ];
        }

        $refund_voucher_res = $this->where($where)->update($up_data);
        return $refund_voucher_res;
    }

    /**
     * @Function notOpenVoucher
     * @Notes: 生成退款凭证
     * @author: 刘子鹤
     * @CreateTime: 2019/5/24
     * @remark:
     */
    public function createVoucher()
    {
        $generate_voucher_res = $this->generateVoucher('refund_voucher');
        return $generate_voucher_res;
    }

    /**
     * [RecalculationVoucher 重新计算，生成退款凭证]
     * @Author           刘子鹤
     * @CreateTime       2019-06-20
     * @LatestUpdateTime 2019-06-20
     * @remark           [remark]
     * @copyright        [copyright]
     * @license          [license]
     * @version          [version]
     */
    public function RecalculationVoucher($data)
    {
        $tkid = $data['tkid'] ?? '';
        $type = $data['type'] ?? '';
        if ($tkid && $type) {
            $this->where('tkid', $tkid)->where('type', $type)->delete();
            switch ($type) {
                case 1:
                    $this->createRefundVoucher($tkid);
                    break;
                case 2:
                    $this->createInterimRefundVoucher($tkid);
                    break;
                case 3:
                    $this->createMonthLastDayRefundVoucher($tkid);
                    break;
            }
        }
    }

    public function generateVoucher($redis_pop_name)
    {
        $create_voucher_res = '';
        $redis_client = Redis::getInstance();
        $tkid = $redis_client->LPOP($redis_pop_name);
        Log::write('生成退款凭证时退款单号打出队列, tkid:' . $tkid . '; 时间：' . date("Y-m-d H:i:s", time()));
        $refund_map['tkid'] = $tkid;
        $refund_voucher_find_obj = $this->where($refund_map)->select();
        $is_up_voucher_num_arr = [];
        if ($refund_voucher_find_obj) { // 查询 有没有已生成的退款凭证，如果有，删除，再生成，为重新计算做准备
            foreach ($refund_voucher_find_obj as $value) {
                $is_up_voucher_num_arr[$value['account_num']]['voucher_num'] = $value['voucher_num'];
                $is_up_voucher_num_arr[$value['account_num']]['create_time'] = $value['create_time'];
                $is_up_voucher_num_arr[$value['account_num']]['account_num'] = $value['account_num'];
            }
            $this->where($refund_map)->delete();
        }
        $refundModel = new Refund();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        if ($refund_find_obj['is_kt'] == '0' || $refund_find_obj['is_kt'] == '2' || $refund_find_obj['refund_type'] == 4) {
            $create_voucher_res = $this->notOpenVoucher($tkid); // 生成未开通凭证
        }
        if ($refund_find_obj['is_kt'] == '1' && $refund_find_obj['refund_type'] != 4) {
            $zordersModel = new Zorders();
            $zorders = $zordersModel->where('orderid', $refund_find_obj['orderid'])->find();
            $fw_start_time = $zorders['fwstart'] ?? 0;

            $bank_map['payType'] = $refund_find_obj['payType'];
            $bankModel = new Bank();
            $bank_find_obj = $bankModel->where($bank_map)->find();
            $product_map['id'] = $refund_find_obj['zu'];
            $departmentModel = new Depart();
            if (isset($zorders['u8_account']) && $zorders['u8_account'] != '') {
                $account = $zorders['u8_account'];
            } else {
                $department_find_obj = $departmentModel->where($product_map)->find();
                $account = $department_find_obj['account'];
            }

            if ($bank_find_obj['accountNum'] == $account) { // 1
                // 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时  1  都相同
                if ($fw_start_time < strtotime('2022-01-01')) {
                    $this->openVoucherOld($tkid); // 开通后退款凭证
                } else {
                    $this->openVoucher($tkid); // 开通后退款凭证 (订单开通部门和退款选择付款方式相同)
                }
            }
            if ($account != $bank_find_obj['accountNum']) { // 2
                //2 当订单的开通部门所属账套为桥西或石家庄分公司，且退款方式（付款方式）,对应的所属账套为总部时，在两个账套同时生成一张凭证
                if ($fw_start_time < strtotime('2022-01-01')) {
                    $this->openVoucherBmOld($tkid); //开通后退款凭证 -- 分公司账套凭证
                    $this->openVoucherZbOld($tkid); // 开通后退款凭证 -- 总部账套凭证
                } else {
                    $this->openVoucherBm($tkid); //开通后退款凭证 -- 分公司账套凭证  (订单在分公司开通，退款在总部退)
                    $this->openVoucherZb($tkid); // 开通后退款凭证 -- 总部账套凭证  (订单在分公司开通，退款在总部退)
                }
            }
        }
        // 重新计算时执行
        if (!empty($is_up_voucher_num_arr)) {
            foreach ($is_up_voucher_num_arr as $key => $value) {
                $refund_voucher_map = [
                    'tkid' => $tkid,
                    'account_num' => $value['account_num']
                ];
                $up_data_arr = [
                    'voucher_num' => $value['voucher_num'],
                    'create_time' => $value['create_time']
                ];
                $up_refund_voucher_res = $this->where($refund_voucher_map)->update($up_data_arr);
                if ($up_refund_voucher_res) {
                    Log::write('退款凭证重新计算时更新出错：is_up_voucher_num_arr：' . json_encode($is_up_voucher_num_arr) . ', tkid' . $tkid . '; ');
                }
            }
        }
        return $create_voucher_res;
    }

    /**
     *  创建普通退款凭证
     * @param $tkid
     * @return bool|int|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createRefundVoucher($tkid)
    {
        Log::write('生成退款凭证, tkid:' . $tkid . ', 时间：' . date("Y-m-d H:i:s", time()), 'zorder');
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        if ($refund_find_obj['is_kt'] == '0' || $refund_find_obj['refund_type'] == 4) {
            $this->notOpenVoucher($tkid); // 生成未开通凭证
        } else {
            $zordersModel = new Zorders();
            $zorders = $zordersModel->where('orderid', $refund_find_obj['orderid'])->find();
            $bank_map['payType'] = $refund_find_obj['payType'];
            $bankModel = new Bank();
            $bank_find_obj = $bankModel->where($bank_map)->find();
            $product_map['id'] = $refund_find_obj['zu'];
            if (isset($zorders['u8_account']) && $zorders['u8_account'] != '') {
                $account = $zorders['u8_account'];
            } else {
                $departmentModel = new Depart();
                $department_find_obj = $departmentModel->where('id', $zorders['zu'])->find();
                $account = $department_find_obj['account'];
            }

            if ($refund_find_obj['is_kt'] == '1' && $refund_find_obj['refund_type'] != 4) { // 已开通订单退款凭证
                $orderVoucherStatusModel = new OrderVoucherStatus();
                $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
                if ($bank_find_obj['accountNum'] == $account) { // 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时
                    if ($orderVoucherStatusData['confirm_status'] == 0) {
                        $this->openNoConfirmVoucher($tkid, $orderVoucherStatusData);
                    } else {
                        $this->openConfirmVoucher($tkid, $orderVoucherStatusData);
                    }
                } else {
                    if ($orderVoucherStatusData['confirm_status'] == 0) {
                        $this->openNoConfirmVoucherDifferent($tkid, $orderVoucherStatusData);
                    } else {
                        $this->openConfirmVoucherDifferent($tkid, $orderVoucherStatusData);
                    }
                }
            } elseif ($refund_find_obj['is_kt'] == '2') { // 预开通订单
                if ($bank_find_obj['accountNum'] == $account) { // 订单所属部门账套和退款方式所属账套相同
                    $this->afterOpenVoucher($tkid);
                } else { // 订单所属部门账套和退款方式所属账套不同
                    $this->afterOpenVoucherDifferent($tkid);
                }
            }
        }
    }

    /**
     *  创建过渡的退款凭证
     * @param $tkid
     */
    public function createInterimRefundVoucher($tkid)
    {
        Log::write('创建过渡的退款凭证, tkid:' . $tkid . ', 时间：' . date("Y-m-d H:i:s", time()), 'zorder');
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refund_find_obj = $refundModel->where($refund_map)->find();
        $zordersModel = new Zorders();
        $zorders = $zordersModel->where('orderid', $refund_find_obj['orderid'])->find();
        $bank_map['payType'] = $refund_find_obj['payType'];
        $bankModel = new Bank();
        $bank_find_obj = $bankModel->where($bank_map)->find();
        $product_map['id'] = $refund_find_obj['zu'];
        if (isset($zorders['u8_account']) && $zorders['u8_account'] != '') {
            $account = $zorders['u8_account'];
        } else {
            $departmentModel = new Depart();
            $department_find_obj = $departmentModel->where($product_map)->find();
            $account = $department_find_obj['account'];
        }

        if (($refund_find_obj['is_kt'] == 1 && $refund_find_obj['refund_type'] != 4) || $refund_find_obj['is_kt'] == 2) {
            if ($bank_find_obj['accountNum'] == $account) { // 订单所属部门账套和退款方式所属账套相同
                $this->afterOpenInterimVoucher($tkid);
            } else { // 订单所属部门账套和退款方式所属账套不同
                $this->afterOpenInterimVoucherDifferent($tkid);
            }
            $redis = Redis::getInstance();
            $redis->lpush('createMonthLastDayRefundVoucher', $tkid);
        } elseif ($refund_find_obj['is_kt'] == '0' || $refund_find_obj['refund_type'] == 4) {
            $this->notOpenVoucher($tkid); // 生成未开通凭证
        }
    }

    /**
     *  创建月底最后一天的退款凭证
     * @param $tkid
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createMonthLastDayRefundVoucher($tkid)
    {
        Log::write('生成上个月最后一个交易日的退款凭证, tkid:' . $tkid . ', 时间：' . date("Y-m-d H:i:s", time()), 'zorder');
        $refund_map['tkid'] = $tkid;
        $refundModel = new Refund();
        $refund_find_obj = $refundModel->where($refund_map)->find();


        $zordersModel = new Zorders();
        $zorders = $zordersModel->where('orderid', $refund_find_obj['orderid'])->find();
        $bank_map['payType'] = $refund_find_obj['payType'];
        $bankModel = new Bank();
        $bank_find_obj = $bankModel->where($bank_map)->find();
        $product_map['id'] = $refund_find_obj['zu'];
        if (isset($zorders['u8_account']) && $zorders['u8_account'] != '') {
            $account = $zorders['u8_account'];
        } else {
            $departmentModel = new Depart();
            $department_find_obj = $departmentModel->where($product_map)->find();
            $account = $department_find_obj['account'];
        }

        if ($refund_find_obj['is_kt'] == '1' && $refund_find_obj['refund_type'] != 4) { // 已开通订单退款凭证
            $orderVoucherStatusModel = new OrderVoucherStatus();
            $orderVoucherStatusData = $orderVoucherStatusModel->where('tkid', $tkid)->where('type', 2)->find();
            if ($bank_find_obj['accountNum'] == $account) { // 当订单的开通部门所属账套，与退款方式（付款方式）对应的所属账套相同时
                if ($orderVoucherStatusData['confirm_status'] == 0) {
                    $this->openNoConfirmVoucher($tkid, $orderVoucherStatusData, true);
                } else {
                    $this->openConfirmVoucher($tkid, $orderVoucherStatusData, true);
                }
            } else {
                if ($orderVoucherStatusData['confirm_status'] == 0) {
                    $this->openNoConfirmVoucherDifferent($tkid, $orderVoucherStatusData, true);
                } else {
                    $this->openConfirmVoucherDifferent($tkid, $orderVoucherStatusData, true);
                }
            }
        } elseif ($refund_find_obj['is_kt'] == '2') { // 预开通订单
            if ($bank_find_obj['accountNum'] == $account) { // 订单所属部门账套和退款方式所属账套相同
                $this->afterOpenVoucher($tkid, true);
            } else { // 订单所属部门账套和退款方式所属账套不同
                $this->afterOpenVoucherDifferent($tkid, true);
            }
        }
    }
}
