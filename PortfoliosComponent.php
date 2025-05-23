<?php

namespace Apps\Fintech\Components\Mf\Portfolios;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
use Apps\Fintech\Packages\Accounts\Users\AccountsUsers;
use Apps\Fintech\Packages\Adminltetags\Traits\DynamicTable;
use Apps\Fintech\Packages\Mf\Amcs\MfAmcs;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\MfPortfoliostimeline;
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

    protected $today;

    public function initialize()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')))->toDateString();

        $this->mfPortfoliosPackage = $this->usePackage(MfPortfolios::class);

        $this->mfPortfoliostimelinePackage = $this->usePackage(MfPortfoliostimeline::class);

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
            if (isset($this->getData()['clone'])) {
                $this->mfPortfoliosPackage->clonePortfolio(['id' => $this->getData()['id']]);
            } else {
                $this->view->today = $this->today;

                $users = $this->accountsUsersPackage->getAccountsUserByAccountId($this->access->auth->account()['id']);

                if (!$users) {
                    $users = [];
                }

                $this->view->users = $users;

                $this->view->amcs = $this->mfAmcsPackage->getAll()->mfamcs;

                if (!isset($this->getData()['mode']) && $this->getData()['id'] != 0) {
                    $this->view->mode = 'transact';
                } else if ((isset($this->getData()['mode']) && $this->getData()['mode'] === 'edit') ||
                           $this->getData()['id'] == 0
                ) {
                    $this->view->mode = 'edit';
                } else if (isset($this->getData()['mode']) && $this->getData()['mode'] === 'transact' && $this->getData()['id'] != 0) {
                    $this->view->mode = 'transact';
                } else if (isset($this->getData()['mode']) && $this->getData()['mode'] === 'timeline' && $this->getData()['id'] != 0) {
                    $this->view->mode = 'timeline';
                }

                if ($this->getData()['id'] != 0) {
                    $portfolio = $this->mfPortfoliosPackage->getPortfolioById((int) $this->getData()['id']);

                    if ($this->view->mode === 'timeline') {
                        if ($portfolio && count($portfolio['investments']) > 0) {
                            $getTimelineDate = $portfolio['start_date'];

                            if (isset($this->getData()['date'])) {
                                try {
                                    $getTimelineDate = (\Carbon\Carbon::parse($this->getData()['date']))->toDateString();
                                } catch (\throwable $e) {
                                    return $this->throwIdNotFound();
                                }

                                // $requestedDate = (\Carbon\Carbon::parse($getTimelineDate));
                                // trace([$requestedDate]);
                            }

                            $mainPortfolio = $portfolio;

                            $portfolio = $this->mfPortfoliostimelinePackage->getPortfoliotimelineByPortfolioAndTimeline($portfolio, $getTimelineDate);

                            $this->view->timelineBorwserOptions = $this->mfPortfoliostimelinePackage->getAvailableTimelineBrowserOptions();
                            $this->view->timelineBrowse = 'day';

                            if (isset($this->getData()['browse'])) {
                                $browseKeys = array_keys($this->view->timelineBorwserOptions);

                                if (in_array(strtolower($this->getData()['browse']), $browseKeys)) {
                                    $this->view->timelineBrowse = strtolower($this->getData()['browse']);
                                }
                            }
                        } else {
                            return $this->throwIdNotFound();
                        }
                    }

                    if (!$portfolio) {
                        return $this->throwIdNotFound();
                    }

                    if ($portfolio['investments'] && count($portfolio['investments']) > 0) {
                        $schemesPackage = $this->usepackage(MfSchemes::class);

                        foreach ($portfolio['investments'] as $amfiCode => &$investment) {
                            $scheme = $schemesPackage->getMfTypeByAmfiCode($amfiCode);
                            $portfolio['investments'][$amfiCode]['scheme'] = $scheme;

                            array_walk($investment, function($value, $key) use (&$investment) {
                                if ($key === 'amount' ||
                                    $key === 'sold_amount' ||
                                    $key === 'latest_value' ||
                                    $key === 'diff'
                                ) {
                                    if ($value) {
                                        $investment[$key] =
                                            str_replace('EN_ ',
                                                    '',
                                                    (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                        ->formatCurrency($value, 'en_IN')
                                        );
                                    }
                                }
                            });
                        }

                        if ($portfolio['transactions'] && count($portfolio['transactions']) > 0) {
                            foreach ($portfolio['transactions'] as $transactionId => &$transaction) {
                                $scheme = $schemesPackage->getMfTypeByAmfiCode($transaction['amfi_code']);

                                $portfolio['transactions'][$transactionId]['scheme'] = $scheme;

                                array_walk($transaction, function($value, $key) use (&$transaction) {
                                    if ($key === 'amount' ||
                                        $key === 'available_amount' ||
                                        $key === 'latest_value' ||
                                        $key === 'diff'
                                    ) {
                                        if ($value) {
                                            $transaction[$key] =
                                                str_replace('EN_ ',
                                                        '',
                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                            ->formatCurrency($value, 'en_IN')
                                            );
                                        }
                                    }
                                });
                            }

                            $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true);
                            $portfolio['transactions'] = array_reverse($portfolio['transactions'], true);
                        }
                    }

                    array_walk($portfolio, function($value, $key) use (&$portfolio) {
                        if ($key === 'invested_amount' ||
                            $key === 'return_amount' ||
                            $key === 'sold_amount' ||
                            $key === 'total_value' ||
                            $key === 'profit_loss'
                        ) {
                            if ($value) {
                                $portfolio[$key] =
                                    str_replace('EN_ ',
                                            '',
                                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                ->formatCurrency($value, 'en_IN')
                                );
                            }
                        }
                    });

                    if (!$portfolio['xirr']) {
                        $portfolio['xirr'] = 0;
                    }

                    $this->view->portfolio = $portfolio;
                }

                $this->view->pick('portfolios/view');

                return;
            }
        }

        $conditions =
            [
                'conditions'                => '-|account_id|equals|' . $this->access->auth->account()['id'] . '&'
            ];

        $controlActions =
            [
                'includeQ'              => true,
                'actionsToEnable'       =>
                [
                    'view'      => [
                        'title' => 'transact',
                        'icon'  => 'exchange-alt',
                        'type'  => 'primary',
                        'link'  => 'mf/portfolios/q/mode/transact'
                    ],
                    'edit'      => 'mf/portfolios/q/mode/edit',
                    'clone'     => 'mf/portfolios/q/',
                    'remove'    => 'mf/portfolios/remove/q/',
                    'divider'   => '',
                    'timeline'  => [
                        'title'             => 'Timeline',
                        'icon'              => 'timeline',
                        'buttonType'        => 'info',
                        'additionalClass'   => 'timelineMode contentAjaxLink',
                        'link'              => 'mf/portfolios/q/mode/timeline'
                    ]
                ]
            ];

        $replaceColumns =
            function ($dataArr) {
                if ($dataArr && is_array($dataArr) && count($dataArr) > 0) {
                    foreach ($dataArr as $key => &$data) {
                        if ($data['account_id'] !== $this->access->auth->account()['id']) {
                            unset($dataArr[$key]);
                        } else {
                            array_walk($data, function($value, $key) use (&$data) {
                                if ($key === 'invested_amount' ||
                                    $key === 'total_value'
                                ) {
                                    if ($value) {
                                        if ($key === 'total_value') {
                                            if (is_string($data['invested_amount'])) {
                                                $investedAmount = (float) str_replace(',', '', $data['invested_amount']);
                                            } else {
                                                $investedAmount = $data['invested_amount'];
                                            }

                                            if ($value > $data['invested_amount']) {
                                                $color = 'success';
                                            } else if ($value < $data['invested_amount']) {
                                                $color = 'danger';
                                            } else if ($value === $data['invested_amount']) {
                                                $color = 'primary';
                                            }

                                            $data[$key] = '<span class="text-' . $color . '">' .
                                                str_replace('EN_ ',
                                                        '',
                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                            ->formatCurrency($value, 'en_IN')
                                                ) .
                                                '</span>';
                                        } else {
                                            $data[$key] =
                                                str_replace('EN_ ',
                                                        '',
                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                            ->formatCurrency($value, 'en_IN')
                                            );
                                        }
                                    }
                                }
                            });

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

    public function recalculatePortfolioAction()
    {
        $this->requestIsPost();

        if (isset($this->postData()['timelineDate'])) {
            $portfolio = $this->mfPortfoliosPackage->getPortfolioById((int) $this->postData()['portfolio_id']);

            if ($portfolio) {
                $this->mfPortfoliostimelinePackage->getPortfoliotimelineByPortfolioAndTimeline(
                    $portfolio,
                    $this->postData()['timelineDate'],
                    true
                );

                $this->addResponse(
                    $this->mfPortfoliostimelinePackage->packagesData->responseMessage,
                    $this->mfPortfoliostimelinePackage->packagesData->responseCode,
                    $this->mfPortfoliostimelinePackage->packagesData->responseData ?? []
                );

                return true;
            }

            return $this->throwIdNotFound();
        } else {
            $this->mfPortfoliosPackage->recalculatePortfolio($this->postData());

            $this->addResponse(
                $this->mfPortfoliosPackage->packagesData->responseMessage,
                $this->mfPortfoliosPackage->packagesData->responseCode,
                $this->mfPortfoliosPackage->packagesData->responseData ?? []
            );
        }
    }

    public function getPortfolioTimelineDateByBrowseActionAction()
    {
        $this->requestIsPost();

        $portfolio = $this->mfPortfoliosPackage->getPortfolioById((int) $this->postData()['portfolio_id']);

        if ($portfolio) {
            $this->mfPortfoliostimelinePackage->getPortfolioTimelineDateByBrowseAction($portfolio, $this->postData());

            $this->addResponse(
                $this->mfPortfoliostimelinePackage->packagesData->responseMessage,
                $this->mfPortfoliostimelinePackage->packagesData->responseCode,
                $this->mfPortfoliostimelinePackage->packagesData->responseData ?? []
            );

            return true;
        }

        return $this->throwIdNotFound();
    }
}