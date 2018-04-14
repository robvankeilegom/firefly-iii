<?php
/**
 * RuleController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use ExpandedForm;
use FireflyIII\Http\Requests\RuleFormRequest;
use FireflyIII\Http\Requests\SelectTransactionsRequest;
use FireflyIII\Http\Requests\TestRuleFormRequest;
use FireflyIII\Jobs\ExecuteRuleOnExistingTransactions;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\TransactionRules\TransactionMatcher;
use Illuminate\Http\Request;
use Log;
use Preferences;
use Session;
use Throwable;
use View;

/**
 * Class RuleController.
 */
class RuleController extends Controller
{
    /**
     * RuleController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware(
            function ($request, $next) {
                app('view')->share('title', trans('firefly.rules'));
                app('view')->share('mainTitleIcon', 'fa-random');

                return $next($request);
            }
        );
    }

    /**
     * Create a new rule. It will be stored under the given $ruleGroup.
     *
     * @param Request   $request
     * @param RuleGroup $ruleGroup
     *
     * @return View
     *
     */
    public function create(Request $request, RuleGroupRepositoryInterface $ruleGroupRepository, BillRepositoryInterface $billRepository, RuleGroup $ruleGroup)
    {
        $this->createDefaultRuleGroup();
        $this->createDefaultRule();
        $bill         = null;
        $billId       = (int)$request->get('fromBill');
        $preFilled    = [];
        $groups       = ExpandedForm::makeSelectList($ruleGroupRepository->get());
        $oldTriggers  = [];
        $oldActions   = [];
        $returnToBill = false;

        if ($request->get('return') === 'true') {
            $returnToBill = true;
        }

        // has bill?
        if ($billId > 0) {
            $bill = $billRepository->find($billId);
        }

        // has old input?
        if ($request->old()) {
            $oldTriggers = $this->getPreviousTriggers($request);
            $oldActions  = $this->getPreviousActions($request);

        }
        // has existing bill refered to in URI?
        if (null !== $bill && !$request->old()) {

            // create some sensible defaults:
            $preFilled['title']       = trans('firefly.new_rule_for_bill_title', ['name' => $bill->name]);
            $preFilled['description'] = trans('firefly.new_rule_for_bill_description', ['name' => $bill->name]);
            $request->session()->flash('preFilled', $preFilled);

            // get triggers and actions for bill:
            $oldTriggers = $this->getTriggersForBill($bill);
            $oldActions  = $this->getActionsForBill($bill);
        }

        $triggerCount = \count($oldTriggers);
        $actionCount  = \count($oldActions);
        $subTitleIcon = 'fa-clone';
        $subTitle     = trans('firefly.make_new_rule', ['title' => $ruleGroup->title]);

        // put previous url in session if not redirect from store (not "create another").
        if (true !== session('rules.create.fromStore')) {
            $this->rememberPreviousUri('rules.create.uri');
        }
        Session::forget('rules.create.fromStore');

        return view(
            'rules.rule.create',
            compact(
                'subTitleIcon', 'oldTriggers', 'returnToBill', 'groups', 'preFilled', 'bill', 'oldActions', 'triggerCount', 'actionCount', 'ruleGroup',
                'subTitle'
            )
        );
    }

    /**
     * Delete a given rule.
     *
     * @param Rule $rule
     *
     * @return View
     */
    public function delete(Rule $rule)
    {
        $subTitle = trans('firefly.delete_rule', ['title' => $rule->title]);

        // put previous url in session
        $this->rememberPreviousUri('rules.delete.uri');

        return view('rules.rule.delete', compact('rule', 'subTitle'));
    }

    /**
     * Actually destroy the given rule.
     *
     * @param Rule                    $rule
     * @param RuleRepositoryInterface $repository
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(RuleRepositoryInterface $repository, Rule $rule)
    {
        $title = $rule->title;
        $repository->destroy($rule);

        Session::flash('success', trans('firefly.deleted_rule', ['title' => $title]));
        Preferences::mark();

        return redirect($this->getPreviousUri('rules.delete.uri'));
    }

    /**
     * @param RuleRepositoryInterface $repository
     * @param Rule                    $rule
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function down(RuleRepositoryInterface $repository, Rule $rule)
    {
        $repository->moveDown($rule);

        return redirect(route('rules.index'));
    }

    /**
     * @param Request                 $request
     * @param RuleRepositoryInterface $repository
     * @param Rule                    $rule
     *
     * @return View
     *




     */
    public function edit(Request $request, RuleRepositoryInterface $repository, Rule $rule)
    {
        /** @var RuleGroupRepositoryInterface $ruleGroupRepository */
        $ruleGroupRepository = app(RuleGroupRepositoryInterface::class);
        $ruleGroups          = ExpandedForm::makeSelectList($ruleGroupRepository->get());
        $triggerCount        = 0;
        $actionCount         = 0;
        $oldActions          = [];
        $oldTriggers         = [];
        // has old input?
        if (count($request->old()) > 0) {
            $oldTriggers  = $this->getPreviousTriggers($request);
            $triggerCount = count($oldTriggers);
            $oldActions   = $this->getPreviousActions($request);
            $actionCount  = count($oldActions);
        }

        // overrule old input when it as no rule data:
        if (0 === $triggerCount && 0 === $actionCount) {
            $oldTriggers  = $this->getCurrentTriggers($rule);
            $triggerCount = count($oldTriggers);
            $oldActions   = $this->getCurrentActions($rule);
            $actionCount  = count($oldActions);
        }

        // get rule trigger for update / store-journal:
        $primaryTrigger = $repository->getPrimaryTrigger($rule);
        $subTitle       = trans('firefly.edit_rule', ['title' => $rule->title]);

        // put previous url in session if not redirect from store (not "return_to_edit").
        if (true !== session('rules.edit.fromUpdate')) {
            $this->rememberPreviousUri('rules.edit.uri');
        }
        Session::forget('rules.edit.fromUpdate');

        return view(
            'rules.rule.edit',
            compact(
                'rule',
                'subTitle',
                'primaryTrigger',
                'oldTriggers',
                'oldActions',
                'triggerCount',
                'actionCount',
                'ruleGroups'
            )
        );
    }

    /**
     * Execute the given rule on a set of existing transactions.
     *
     * @param SelectTransactionsRequest  $request
     * @param AccountRepositoryInterface $repository
     * @param Rule                       $rule
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     * @internal param RuleGroup $ruleGroup
     */
    public function execute(SelectTransactionsRequest $request, AccountRepositoryInterface $repository, Rule $rule)
    {
        // Get parameters specified by the user
        $accounts  = $repository->getAccountsById($request->get('accounts'));
        $startDate = new Carbon($request->get('start_date'));
        $endDate   = new Carbon($request->get('end_date'));

        // Create a job to do the work asynchronously
        $job = new ExecuteRuleOnExistingTransactions($rule);

        // Apply parameters to the job
        $job->setUser(auth()->user());
        $job->setAccounts($accounts);
        $job->setStartDate($startDate);
        $job->setEndDate($endDate);

        // Dispatch a new job to execute it in a queue
        $this->dispatch($job);

        // Tell the user that the job is queued
        Session::flash('success', (string)trans('firefly.applied_rule_selection', ['title' => $rule->title]));

        return redirect()->route('rules.index');
    }

    /**
     * @param RuleGroupRepositoryInterface $repository
     *
     * @return View
     */
    public function index(RuleGroupRepositoryInterface $repository)
    {
        $this->createDefaultRuleGroup();
        $this->createDefaultRule();
        $ruleGroups = $repository->getRuleGroupsWithRules(auth()->user());

        return view('rules.index', compact('ruleGroups'));
    }

    /**
     * @param Request                 $request
     * @param RuleRepositoryInterface $repository
     * @param Rule                    $rule
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorderRuleActions(Request $request, RuleRepositoryInterface $repository, Rule $rule)
    {
        $ids = $request->get('actions');
        if (is_array($ids)) {
            $repository->reorderRuleActions($rule, $ids);
        }

        return response()->json('true');
    }

    /**
     * @param Request                 $request
     * @param RuleRepositoryInterface $repository
     * @param Rule                    $rule
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorderRuleTriggers(Request $request, RuleRepositoryInterface $repository, Rule $rule)
    {
        $ids = $request->get('triggers');
        if (is_array($ids)) {
            $repository->reorderRuleTriggers($rule, $ids);
        }

        return response()->json('true');
    }

    /**
     * @param AccountRepositoryInterface $repository
     * @param Rule                       $rule
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function selectTransactions(AccountRepositoryInterface $repository, Rule $rule)
    {
        // does the user have shared accounts?
        $accounts        = $repository->getAccountsByType([AccountType::ASSET]);
        $accountList     = ExpandedForm::makeSelectList($accounts);
        $checkedAccounts = array_keys($accountList);
        $first           = session('first')->format('Y-m-d');
        $today           = Carbon::create()->format('Y-m-d');
        $subTitle        = (string)trans('firefly.apply_rule_selection', ['title' => $rule->title]);

        return view('rules.rule.select-transactions', compact('checkedAccounts', 'accountList', 'first', 'today', 'rule', 'subTitle'));
    }

    /**
     * @param RuleFormRequest         $request
     * @param RuleRepositoryInterface $repository
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store(RuleFormRequest $request, RuleRepositoryInterface $repository)
    {
        $data = $request->getRuleData();
        $rule = $repository->store($data);
        session()->flash('success', trans('firefly.stored_new_rule', ['title' => $rule->title]));
        Preferences::mark();

        // redirect to show bill.
        if ($request->get('return_to_bill') === 'true' && (int)$request->get('bill_id') > 0) {
            return redirect(route('bills.show', [(int)$request->get('bill_id')]));
        }

        // redirect to new bill creation.
        if ((int)$request->get('bill_id') > 0) {
            return redirect($this->getPreviousUri('bills.create.uri'));
        }


        if (1 === (int)$request->get('create_another')) {
            // @codeCoverageIgnoreStart
            Session::put('rules.create.fromStore', true);

            return redirect(route('rules.create', [$data['rule_group_id']]))->withInput();
            // @codeCoverageIgnoreEnd
        }

        return redirect($this->getPreviousUri('rules.create.uri'));
    }

    /**
     * This method allows the user to test a certain set of rule triggers. The rule triggers are passed along
     * using the URL parameters (GET), and are usually put there using a Javascript thing.
     *
     * This method will parse and validate those rules and create a "TransactionMatcher" which will attempt
     * to find transaction journals matching the users input. A maximum range of transactions to try (range) and
     * a maximum number of transactions to return (limit) are set as well.
     *
     * @param TestRuleFormRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     *
     */
    public function testTriggers(TestRuleFormRequest $request)
    {
        // build trigger array from response
        $triggers = $this->getValidTriggerList($request);

        if (0 === count($triggers)) {
            return response()->json(['html' => '', 'warning' => trans('firefly.warning_no_valid_triggers')]); // @codeCoverageIgnore
        }

        $limit = (int)config('firefly.test-triggers.limit');
        $range = (int)config('firefly.test-triggers.range');

        /** @var TransactionMatcher $matcher */
        $matcher = app(TransactionMatcher::class);
        $matcher->setLimit($limit);
        $matcher->setRange($range);
        $matcher->setTriggers($triggers);
        $matchingTransactions = $matcher->findTransactionsByTriggers();

        // Warn the user if only a subset of transactions is returned
        $warning = '';
        if (count($matchingTransactions) === $limit) {
            $warning = trans('firefly.warning_transaction_subset', ['max_num_transactions' => $limit]); // @codeCoverageIgnore
        }
        if (0 === count($matchingTransactions)) {
            $warning = trans('firefly.warning_no_matching_transactions', ['num_transactions' => $range]); // @codeCoverageIgnore
        }

        // Return json response
        $view = view('list.journals-tiny', ['transactions' => $matchingTransactions])->render();

        return response()->json(['html' => $view, 'warning' => $warning]);
    }

    /**
     * This method allows the user to test a certain set of rule triggers. The rule triggers are grabbed from
     * the rule itself.
     *
     * This method will parse and validate those rules and create a "TransactionMatcher" which will attempt
     * to find transaction journals matching the users input. A maximum range of transactions to try (range) and
     * a maximum number of transactions to return (limit) are set as well.
     *
     *
     * @param Rule $rule
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws Throwable
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    public function testTriggersByRule(Rule $rule)
    {
        $triggers = $rule->ruleTriggers;

        if (0 === count($triggers)) {
            return response()->json(['html' => '', 'warning' => trans('firefly.warning_no_valid_triggers')]); // @codeCoverageIgnore
        }

        $limit = (int)config('firefly.test-triggers.limit');
        $range = (int)config('firefly.test-triggers.range');

        /** @var TransactionMatcher $matcher */
        $matcher = app(TransactionMatcher::class);
        $matcher->setLimit($limit);
        $matcher->setRange($range);
        $matcher->setRule($rule);
        $matchingTransactions = $matcher->findTransactionsByRule();

        // Warn the user if only a subset of transactions is returned
        $warning = '';
        if (\count($matchingTransactions) === $limit) {
            $warning = trans('firefly.warning_transaction_subset', ['max_num_transactions' => $limit]); // @codeCoverageIgnore
        }
        if (0 === \count($matchingTransactions)) {
            $warning = trans('firefly.warning_no_matching_transactions', ['num_transactions' => $range]); // @codeCoverageIgnore
        }

        // Return json response
        $view = view('list.journals-tiny', ['transactions' => $matchingTransactions])->render();

        return response()->json(['html' => $view, 'warning' => $warning]);
    }

    /**
     * @param RuleRepositoryInterface $repository
     * @param Rule                    $rule
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function up(RuleRepositoryInterface $repository, Rule $rule)
    {
        $repository->moveUp($rule);

        return redirect(route('rules.index'));
    }

    /**
     * @param RuleRepositoryInterface $repository
     * @param RuleFormRequest         $request
     * @param Rule                    $rule
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function update(RuleRepositoryInterface $repository, RuleFormRequest $request, Rule $rule)
    {
        $data = $request->getRuleData();
        $repository->update($rule, $data);

        Session::flash('success', trans('firefly.updated_rule', ['title' => $rule->title]));
        Preferences::mark();

        if (1 === (int)$request->get('return_to_edit')) {
            // @codeCoverageIgnoreStart
            Session::put('rules.edit.fromUpdate', true);

            return redirect(route('rules.edit', [$rule->id]))->withInput(['return_to_edit' => 1]);
            // @codeCoverageIgnoreEnd
        }

        return redirect($this->getPreviousUri('rules.edit.uri'));
    }

    private function createDefaultRule()
    {
        /** @var RuleRepositoryInterface $repository */
        $repository = app(RuleRepositoryInterface::class);

        if (0 === $repository->count()) {
            $data = [
                'rule_group_id'       => $repository->getFirstRuleGroup()->id,
                'stop_processing'     => 0,
                'title'               => trans('firefly.default_rule_name'),
                'description'         => trans('firefly.default_rule_description'),
                'trigger'             => 'store-journal',
                'rule-trigger-values' => [
                    trans('firefly.default_rule_trigger_description'),
                    trans('firefly.default_rule_trigger_from_account'),
                ],
                'rule-action-values'  => [
                    trans('firefly.default_rule_action_prepend'),
                    trans('firefly.default_rule_action_set_category'),
                ],

                'rule-triggers' => ['description_is', 'from_account_is'],
                'rule-actions'  => ['prepend_description', 'set_category'],
            ];

            $repository->store($data);
        }
    }

    /**
     *
     */
    private function createDefaultRuleGroup()
    {
        /** @var RuleGroupRepositoryInterface $repository */
        $repository = app(RuleGroupRepositoryInterface::class);

        if (0 === $repository->count()) {
            $data = [
                'title'       => trans('firefly.default_rule_group_name'),
                'description' => trans('firefly.default_rule_group_description'),
            ];

            $repository->store($data);
        }
    }

    /**
     * @param Bill $bill
     *
     * @return array
     */
    private function getActionsForBill(Bill $bill): array
    {
        $actions = [];
        try {
            $actions[] = view(
                'rules.partials.action',
                [
                    'oldAction'  => 'link_to_bill',
                    'oldValue'   => $bill->name,
                    'oldChecked' => false,
                    'count'      => 1,
                ]
            )->render();
        } catch (Throwable $e) {
            Log::debug(sprintf('Throwable was thrown in getActionsForBill(): %s', $e->getMessage()));
        }

        return $actions;
    }

    /**
     * @param Rule $rule
     *
     * @return array
     *

     */
    private function getCurrentActions(Rule $rule)
    {
        $index   = 0;
        $actions = [];

        /** @var RuleAction $entry */
        foreach ($rule->ruleActions as $entry) {
            $count = ($index + 1);
            try {
                $actions[] = view(
                    'rules.partials.action',
                    [
                        'oldAction'  => $entry->action_type,
                        'oldValue'   => $entry->action_value,
                        'oldChecked' => $entry->stop_processing,
                        'count'      => $count,
                    ]
                )->render();
            } catch (Throwable $e) {
                Log::debug(sprintf('Throwable was thrown in getCurrentActions(): %s', $e->getMessage()));
            }
            ++$index;
        }

        return $actions;
    }

    /**
     * @param Rule $rule
     *
     * @return array
     *

     */
    private function getCurrentTriggers(Rule $rule)
    {
        $index    = 0;
        $triggers = [];

        /** @var RuleTrigger $entry */
        foreach ($rule->ruleTriggers as $entry) {
            if ('user_action' !== $entry->trigger_type) {
                $count = ($index + 1);
                try {
                    $triggers[] = view(
                        'rules.partials.trigger',
                        [
                            'oldTrigger' => $entry->trigger_type,
                            'oldValue'   => $entry->trigger_value,
                            'oldChecked' => $entry->stop_processing,
                            'count'      => $count,
                        ]
                    )->render();
                } catch (Throwable $e) {
                    Log::debug(sprintf('Throwable was thrown in getCurrentTriggers(): %s', $e->getMessage()));
                }
                ++$index;
            }
        }

        return $triggers;
    }

    /**
     * @param Request $request
     *
     * @return array
     *

     */
    private function getPreviousActions(Request $request)
    {
        $newIndex = 0;
        $actions  = [];
        /** @var array $oldActions */
        $oldActions = is_array($request->old('rule-action')) ? $request->old('rule-action') : [];
        foreach ($oldActions as $index => $entry) {
            $count   = ($newIndex + 1);
            $checked = isset($request->old('rule-action-stop')[$index]) ? true : false;
            try {
                $actions[] = view(
                    'rules.partials.action',
                    [
                        'oldAction'  => $entry,
                        'oldValue'   => $request->old('rule-action-value')[$index],
                        'oldChecked' => $checked,
                        'count'      => $count,
                    ]
                )->render();
            } catch (Throwable $e) {
                Log::debug(sprintf('Throwable was thrown in getPreviousActions(): %s', $e->getMessage()));
            }
            ++$newIndex;
        }

        return $actions;
    }

    /**
     * @param Request $request
     *
     * @return array
     *

     */
    private function getPreviousTriggers(Request $request)
    {
        $newIndex = 0;
        $triggers = [];
        /** @var array $oldTriggers */
        $oldTriggers = is_array($request->old('rule-trigger')) ? $request->old('rule-trigger') : [];
        foreach ($oldTriggers as $index => $entry) {
            $count      = ($newIndex + 1);
            $oldChecked = isset($request->old('rule-trigger-stop')[$index]) ? true : false;
            try {
                $triggers[] = view(
                    'rules.partials.trigger',
                    [
                        'oldTrigger' => $entry,
                        'oldValue'   => $request->old('rule-trigger-value')[$index],
                        'oldChecked' => $oldChecked,
                        'count'      => $count,
                    ]
                )->render();
            } catch (Throwable $e) {
                Log::debug(sprintf('Throwable was thrown in getPreviousTriggers(): %s', $e->getMessage()));
            }
            ++$newIndex;
        }

        return $triggers;
    }

    /**
     * Create fake triggers to match the bill's properties
     *
     * @param Bill $bill
     *
     * @return array
     */
    private function getTriggersForBill(Bill $bill): array
    {
        $triggers = [];
        try {
            $triggers[] = view(
                'rules.partials.trigger',
                [
                    'oldTrigger' => 'currency_is',
                    'oldValue'   => $bill->transactionCurrency()->first()->name,
                    'oldChecked' => false,
                    'count'      => 1,
                ]
            )->render();

            $triggers[] = view(
                'rules.partials.trigger',
                [
                    'oldTrigger' => 'amount_more',
                    'oldValue'   => round($bill->amount_min, 12),
                    'oldChecked' => false,
                    'count'      => 2,
                ]
            )->render();

            $triggers[] = view(
                'rules.partials.trigger',
                [
                    'oldTrigger' => 'amount_less',
                    'oldValue'   => round($bill->amount_max, 12),
                    'oldChecked' => false,
                    'count'      => 3,
                ]
            )->render();
            $triggers[] = view(
                'rules.partials.trigger',
                [
                    'oldTrigger' => 'description_contains',
                    'oldValue'   => $bill->name, 12,
                    'oldChecked' => false,
                    'count'      => 4,
                ]
            )->render();
        } catch (Throwable $e) {
            Log::debug(sprintf('Throwable was thrown in getTriggersForBill(): %s', $e->getMessage()));
            Log::debug($e->getTraceAsString());
        }

        return $triggers;
    }

    /**
     * @param TestRuleFormRequest $request
     *
     * @return array
     */
    private function getValidTriggerList(TestRuleFormRequest $request): array
    {
        $triggers = [];
        $data     = [
            'rule-triggers'       => $request->get('rule-trigger'),
            'rule-trigger-values' => $request->get('rule-trigger-value'),
            'rule-trigger-stop'   => $request->get('rule-trigger-stop'),
        ];
        if (\is_array($data['rule-triggers'])) {
            foreach ($data['rule-triggers'] as $index => $triggerType) {
                $data['rule-trigger-stop'][$index] = (int)($data['rule-trigger-stop'][$index] ?? 0.0);
                $triggers[]                        = [
                    'type'           => $triggerType,
                    'value'          => $data['rule-trigger-values'][$index],
                    'stopProcessing' => 1 === (int)$data['rule-trigger-stop'][$index],
                ];
            }
        }

        return $triggers;
    }
}
