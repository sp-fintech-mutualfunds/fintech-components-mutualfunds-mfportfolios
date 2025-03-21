<?php

namespace Apps\Fintech\Components\Mf\Portfolios;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
use Apps\Fintech\Packages\Accounts\Users\AccountsUsers;
use Apps\Fintech\Packages\Adminltetags\Traits\DynamicTable;
use Apps\Fintech\Packages\Mf\Amcs\MfAmcs;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use Apps\Fintech\Packages\Mf\Transactions\MfTransactions;
use System\Base\BaseComponent;

class PortfoliosComponent extends BaseComponent
{
    use DynamicTable;

    protected $mfPortfoliosPackage;

    protected $mfAmcsPackage;

    protected $mfTransactionsPackage;

    protected $accountsUsersPackage;

    public function initialize()
    {
        $this->mfPortfoliosPackage = $this->usePackage(MfPortfolios::class);

        $this->mfAmcsPackage = $this->usePackage(MfAmcs::class);

        $this->mfTransactionsPackage = $this->usePackage(MfTransactions::class);

        $this->accountsUsersPackage = $this->usePackage(AccountsUsers::class);
    }

    /**
     * @acl(name=view)
     */
    public function viewAction()
    {
        $this->view->currencySymbol = '$';
        if (isset($this->access->auth->account()['profile']['locale_country_id'])) {
            $country = $this->basepackages->geoCountries->getById((int) $this->access->auth->account()['profile']['locale_country_id']);

            if ($country && isset($country['currency_symbol'])) {
                $this->view->currencySymbol = $country['currency_symbol'];
            }
        }

        if (isset($this->getData()['id'])) {
            $users = $this->accountsUsersPackage->getAccountsUserByAccountId($this->access->auth->account()['id']);

            if (!$users) {
                $users = [];
            }

            $this->view->users = $users;

            $canSellTransactions = [];

            $this->view->amcs = $this->mfAmcsPackage->getAll()->mfamcs;

            if ($this->getData()['id'] != 0) {
                $portfolio = $this->mfPortfoliosPackage->getPortfolioById((int) $this->getData()['id']);

                if (!$portfolio) {
                    return $this->throwIdNotFound();
                }

                $this->view->mode = 'edit';
                if (isset($this->getData()['mode']) && $this->getData()['mode'] === 'timeline') {
                    $this->view->mode = 'timeline';

                    if (!$portfolio['timeline']) {
                        $portfolio['timeline'] = [];
                    }
                }

                if ($portfolio['transactions'] && count($portfolio['transactions']) > 0) {
                    $schemesPackage = $this->usepackage(MfSchemes::class);

                    foreach ($portfolio['transactions'] as &$transaction) {
                        if ($this->config->databasetype === 'db') {
                            $conditions =
                                [
                                    'conditions'    => 'amfi_code = :amfi_code:',
                                    'bind'          =>
                                        [
                                            'amfi_code'       => (int) $transaction['amfi_code'],
                                        ]
                                ];
                        } else {
                            $conditions =
                                [
                                    'conditions'    => ['amfi_code', '=', (int) $transaction['amfi_code']]
                                ];
                        }

                        $scheme = $schemesPackage->getByParams($conditions);

                        if ($scheme && isset($scheme[0])) {
                            $transaction['scheme'] = $scheme[0];
                            $scheme = $schemesPackage->getSchemeById($transaction['scheme']['id']);

                            if ($transaction['type'] === 'buy' && $transaction['status'] === 'open') {
                                $transaction['available_units'] = $transaction['units_bought'];

                                if ($transaction['units_sold'] > 0) {
                                    $transaction['available_units'] = $transaction['units_bought'] - $transaction['units_sold'];
                                }

                                if (isset($canSellTransactions[$transaction['amfi_code']])) {
                                    $canSellTransactions[$transaction['amfi_code']]['available_units'] += $transaction['available_units'];
                                } else {
                                    $canSellTransactions[$transaction['amfi_code']] = $transaction;
                                }
                            } else {
                                // $transaction['amfi_code'] = $scheme['name'];
                                // $transaction['scheme_name'] = $scheme['name'];

                                $transaction['amount'] =
                                    str_replace('EN_ ',
                                                '',
                                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                    ->formatCurrency($transaction['amount'], 'en_IN')
                                    );
                                $transaction['latest_value'] =
                                    str_replace('EN_ ',
                                                '',
                                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                    ->formatCurrency($transaction['latest_value'], 'en_IN')
                                    );

                                continue;
                            }

                            $canSellTransactions[$transaction['amfi_code']]['available_units'] = round($canSellTransactions[$transaction['amfi_code']]['available_units'], 3);

                            $canSellTransactions[$transaction['amfi_code']]['available_amount'] =
                                str_replace('EN_ ',
                                            '',
                                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                ->formatCurrency(
                                                    $canSellTransactions[$transaction['amfi_code']]['available_units'] * $scheme['navs']['latest_nav'],
                                                    'en_IN'
                                                )
                                );

                            // $transaction['amfi_code'] = $scheme['name'];
                            // $transaction['scheme_name'] = $scheme['name'];
                        }

                        $transaction['amount'] =
                            str_replace('EN_ ',
                                        '',
                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                            ->formatCurrency($transaction['amount'], 'en_IN')
                            );
                        $transaction['latest_value'] =
                            str_replace('EN_ ',
                                        '',
                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                            ->formatCurrency($transaction['latest_value'], 'en_IN')
                            );
                    }
                }

                $portfolio['invested_amount'] =
                    str_replace('EN_ ',
                                '',
                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                    ->formatCurrency($portfolio['invested_amount'], 'en_IN')
                    );

                $portfolio['total_value'] =
                    str_replace('EN_ ',
                                '',
                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                    ->formatCurrency($portfolio['total_value'], 'en_IN')
                    );

                $portfolio['profit_loss'] =
                    str_replace('EN_ ',
                                '',
                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                    ->formatCurrency($portfolio['profit_loss'], 'en_IN')
                    );

                if (!$portfolio['xirr']) {
                    $portfolio['xirr'] = 0;
                }

                if ($portfolio['transactions'] && count($portfolio['transactions']) > 0) {
                    $portfolio['transactions'] = msort($portfolio['transactions'], 'date');
                    $portfolio['transactions'] = array_reverse($portfolio['transactions']);
                }

                $this->view->portfolio = $portfolio;
            }

            $this->view->canSellTransactions = $canSellTransactions;

            $this->view->pick('portfolios/view');

            return;
        }

        $conditions =
            [
                'conditions'                => '-|account_id|equals|' . $this->access->auth->account()['id'] . '&'
            ];

        $controlActions =
            [
                // 'disableActionsForIds'  => [1],
                'actionsToEnable'       =>
                [
                    'edit'      => 'mf/portfolios',
                    'remove'    => 'mf/portfolios/remove'
                ]
            ];

        $replaceColumns =
            function ($dataArr) {
                if ($dataArr && is_array($dataArr) && count($dataArr) > 0) {
                    foreach ($dataArr as $key => &$data) {
                        if ($data['account_id'] !== $this->access->auth->account()['id']) {
                            unset($dataArr[$key]);
                        } else {
                            $data['invested_amount'] =
                                str_replace('EN_ ',
                                            '',
                                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                ->formatCurrency($data['invested_amount'], 'en_IN')
                                );
                            $data['total_value'] =
                                str_replace('EN_ ',
                                            '',
                                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                ->formatCurrency($data['total_value'], 'en_IN')
                                );

                            if ($data['xirr'] < 0) {
                                $data['xirr'] = '<span class="text-danger">' . $data['xirr'] . '</span>';
                            } else if ($data['xirr'] > 0) {
                                $data['xirr'] = '<span class="text-success">' . $data['xirr'] . '</span>';
                            } else if ($data['xirr'] === 0) {
                                $data['xirr'] = '<span class="text-primary">' . $data['xirr'] . '</span>';
                            }
                        }
                    }
                }

                return $dataArr;
            };

        $this->generateDTContent(
            package: $this->mfPortfoliosPackage,
            postUrl: 'mf/portfolios/view',
            postUrlParams: $conditions,
            columnsForTable: ['account_id', 'name', 'invested_amount', 'total_value', 'xirr'],
            withFilter : true,
            columnsForFilter : ['name', 'invested_amount', 'total_value', 'xirr'],
            controlActions : $controlActions,
            dtNotificationTextFromColumn: 'name',
            excludeColumns : ['account_id'],
            dtReplaceColumns: $replaceColumns,
            dtReplaceColumnsTitle : ['invested_amount' => $this->view->currencySymbol . ' Invested Amount', 'total_value' => $this->view->currencySymbol . ' Total Value']
        );

        $this->view->pick('portfolios/list');
    }

    /**
     * @acl(name=add)
     */
    public function addAction()
    {
        $this->requestIsPost();

        $this->mfPortfoliosPackage->addPortfolio($this->postData());

        $this->addResponse(
            $this->mfPortfoliosPackage->packagesData->responseMessage,
            $this->mfPortfoliosPackage->packagesData->responseCode,
            $this->mfPortfoliosPackage->packagesData->responseData ?? []
        );
    }

    /**
     * @acl(name=update)
     */
    public function updateAction()
    {
        $this->requestIsPost();

        $this->mfPortfoliosPackage->updatePortfolio($this->postData());

        $this->addResponse(
            $this->mfPortfoliosPackage->packagesData->responseMessage,
            $this->mfPortfoliosPackage->packagesData->responseCode,
            $this->mfPortfoliosPackage->packagesData->responseData ?? []
        );
    }

    /**
     * @acl(name=remove)
     */
    public function removeAction()
    {
        $this->requestIsPost();

        $this->mfPortfoliosPackage->removePortfolio($this->postData());

        $this->addResponse(
            $this->mfPortfoliosPackage->packagesData->responseMessage,
            $this->mfPortfoliosPackage->packagesData->responseCode,
            $this->mfPortfoliosPackage->packagesData->responseData ?? []
        );
    }

    public function addTransactionAction()
    {
        $this->requestIsPost();

        $this->mfTransactionsPackage->addMfTransaction($this->postData());

        $this->addResponse(
            $this->mfTransactionsPackage->packagesData->responseMessage,
            $this->mfTransactionsPackage->packagesData->responseCode,
            $this->mfTransactionsPackage->packagesData->responseData ?? []
        );
    }

    public function updateTransactionAction()
    {
        $this->requestIsPost();

        $this->mfTransactionsPackage->updateMfTransaction($this->postData());

        $this->addResponse(
            $this->mfTransactionsPackage->packagesData->responseMessage,
            $this->mfTransactionsPackage->packagesData->responseCode,
            $this->mfTransactionsPackage->packagesData->responseData ?? []
        );
    }

    public function removeTransactionAction()
    {
        $this->requestIsPost();

        $this->mfTransactionsPackage->removeMfTransaction($this->postData());

        $this->addResponse(
            $this->mfTransactionsPackage->packagesData->responseMessage,
            $this->mfTransactionsPackage->packagesData->responseCode,
            $this->mfTransactionsPackage->packagesData->responseData ?? []
        );
    }

    public function recalculatePortfolioTransactionsAction()
    {
        $this->requestIsPost();

        $this->mfTransactionsPackage->recalculatePortfolioTransactions($this->postData());

        $this->addResponse(
            $this->mfTransactionsPackage->packagesData->responseMessage,
            $this->mfTransactionsPackage->packagesData->responseCode,
            $this->mfTransactionsPackage->packagesData->responseData ?? []
        );
    }
}