const state = {
    code: null,
    group: null,
    members: [],
    items: []
};

const views = {
    home: document.getElementById('home-view'),
    group: document.getElementById('group-view'),
    loading: document.getElementById('loading-view')
};

const messageBar = document.getElementById('message-bar');

function showView(name) {
    Object.values(views).forEach(view => view.classList.add('hidden'));
    if (views[name]) {
        views[name].classList.remove('hidden');
    }
}

function showMessage(text, type = 'info') {
    messageBar.textContent = text;
    messageBar.className = '';
    messageBar.classList.add(type === 'error' ? 'error' : 'info');
    messageBar.classList.remove('hidden');
    setTimeout(() => {
        messageBar.classList.add('hidden');
    }, 3500);
}

function updateUrlCodeParam(code) {
    const url = new URL(window.location.href);
    if (code) {
        url.searchParams.set('code', code);
    } else {
        url.searchParams.delete('code');
    }

    const relativeUrl = `${url.pathname}${url.search}${url.hash}`;
    history.replaceState({}, '', relativeUrl || '/');
}

function buildGroupUrl(code) {
    const url = new URL(window.location.href);
    url.searchParams.set('code', code);
    return url.toString();
}

function formatCurrency(amount) {
    return Number(amount).toLocaleString('ja-JP', { style: 'currency', currency: 'JPY', maximumFractionDigits: 0 });
}

function getRoleLabel(role) {
    switch (role) {
        case 'adult':
            return 'おとな';
        case 'student':
            return '学生';
        case 'child':
            return 'こども';
        default:
            return 'みんな';
    }
}

async function apiRequest(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        ...options
    });

    const data = await response.json().catch(() => ({ success: false, message: 'サーバーからの情報を読み取れませんでした。' }));

    if (!response.ok) {
        const message = data && data.message ? data.message : 'うまくいきませんでした。もう一度試してみてね。';
        throw new Error(message);
    }

    return data;
}

function updateMemberList() {
    const list = document.getElementById('member-list');
    list.innerHTML = '';

    if (!state.members.length) {
        const empty = document.createElement('p');
        empty.textContent = 'まだメンバーはいません。';
        list.appendChild(empty);
        return;
    }

    state.members.forEach(member => {
        const item = document.createElement('li');
        item.className = 'member-item';
        item.innerHTML = `
            <span><strong>${member.name}</strong></span>
            <span class="member-role">${getRoleLabel(member.role)} / 目安 ${member.default_ratio}</span>
        `;
        list.appendChild(item);
    });
}

function updateMemberSelects() {
    const payerSelect = document.getElementById('payer-select');
    const checkboxContainer = document.getElementById('participant-checkboxes');

    payerSelect.innerHTML = '<option value="">選んでね</option>';
    checkboxContainer.innerHTML = '';

    state.members.forEach(member => {
        const option = document.createElement('option');
        option.value = member.id;
        option.textContent = member.name;
        payerSelect.appendChild(option);

        const wrapper = document.createElement('label');
        wrapper.className = 'checkbox-item';
        wrapper.innerHTML = `
            <input type="checkbox" value="${member.id}">
            <span>${member.name}</span>
        `;
        checkboxContainer.appendChild(wrapper);
    });
}

function renderItems() {
    const container = document.getElementById('item-list');
    container.innerHTML = '';

    if (!state.items.length) {
        const empty = document.createElement('p');
        empty.textContent = 'まだ記録はありません。みんなで使ったものを登録してみよう。';
        container.appendChild(empty);
        return;
    }

    state.items.forEach(item => {
        const card = document.createElement('div');
        card.className = 'item-card';

        const header = document.createElement('div');
        header.className = 'item-header';
        const title = document.createElement('div');
        title.className = 'item-title';
        title.textContent = item.title;
        const amount = document.createElement('div');
        amount.className = 'item-amount';
        amount.textContent = `${formatCurrency(item.amount)} / ${item.payer_name}さん`;
        header.appendChild(title);
        header.appendChild(amount);

        const participants = document.createElement('div');
        participants.className = 'item-participants';
        if (item.participants && item.participants.length) {
            const label = document.createElement('div');
            label.textContent = '参加した人';
            participants.appendChild(label);
            item.participants.forEach(p => {
                const badge = document.createElement('span');
                const share = formatCurrency(p.share_amount);
                badge.textContent = `${p.name} / ${share}`;
                participants.appendChild(badge);
            });
        }

        card.appendChild(header);
        card.appendChild(participants);
        container.appendChild(card);
    });
}

function renderSettlement(data) {
    const container = document.getElementById('settlement-result');
    container.innerHTML = '';

    if (!data) {
        const empty = document.createElement('p');
        empty.textContent = 'まだ計算していません。';
        container.appendChild(empty);
        return;
    }

    const balanceWrap = document.createElement('div');
    balanceWrap.className = 'balances';

    data.balances.forEach(balance => {
        const card = document.createElement('div');
        card.className = 'balance-card';
        card.classList.add(balance.balance >= 0 ? 'receive' : 'pay');
        const amount = formatCurrency(Math.abs(balance.balance));
        card.innerHTML = `<strong>${balance.name}</strong> は ${balance.balance >= 0 ? '受け取る' : '渡す'}予定: ${amount}`;
        balanceWrap.appendChild(card);
    });

    container.appendChild(balanceWrap);

    if (data.settlements && data.settlements.length) {
        const transactions = document.createElement('div');
        transactions.className = 'transactions';
        const title = document.createElement('h4');
        title.textContent = 'やりとりの目安';
        transactions.appendChild(title);

        data.settlements.forEach(tx => {
            const item = document.createElement('div');
            item.className = 'transaction-item';
            item.textContent = `${tx.from} さんから ${tx.to} さんへ ${formatCurrency(tx.amount)} を渡す`;
            transactions.appendChild(item);
        });

        container.appendChild(transactions);
    } else {
        const allClear = document.createElement('p');
        allClear.textContent = 'みんなのバランスはぴったりです。お疲れさまでした！';
        container.appendChild(allClear);
    }
}

function updateGroupInfo() {
    const title = document.getElementById('group-title');
    const codeEl = document.getElementById('group-code');

    if (!state.group) return;

    title.textContent = state.group.name;
    codeEl.textContent = `グループコード: ${state.group.code}`;
}

function setLoading(isLoading) {
    if (isLoading) {
        showView('loading');
    } else {
        showView(state.group ? 'group' : 'home');
    }
}

async function loadGroup(code) {
    if (!code) return;
    state.code = code;
    setLoading(true);
    try {
        const data = await apiRequest(`api/groups.php?action=get_group&code=${encodeURIComponent(code)}`);
        state.group = data.group;
        state.members = data.members;
        state.items = [];
        updateGroupInfo();
        updateMemberList();
        updateMemberSelects();
        updateUrlCodeParam(state.group.code);
        renderItems();
        showView('group');
        fetchItems();
    } catch (error) {
        showMessage(error.message || 'グループを見つけられませんでした。', 'error');
        state.code = null;
        state.group = null;
        state.members = [];
        state.items = [];
        updateUrlCodeParam(null);
        showView('home');
    }
}

async function fetchItems() {
    if (!state.code) return;
    try {
        const data = await apiRequest(`api/items.php?action=list&code=${encodeURIComponent(state.code)}`);
        state.items = data.items || [];
        renderItems();
    } catch (error) {
        renderItems();
        showMessage(error.message || '記録を読み込めませんでした。', 'error');
    }
}

function gatherParticipants() {
    const checkboxes = document.querySelectorAll('#participant-checkboxes input[type="checkbox"]:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function resetItemForm() {
    document.getElementById('add-item-form').reset();
    updateMemberSelects();
}

document.getElementById('create-group-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    const formData = Object.fromEntries(new FormData(form));
    if (!formData.name) {
        showMessage('グループの名前を入れてね。', 'error');
        return;
    }

    try {
        setLoading(true);
        const data = await apiRequest('api/groups.php?action=create_group', {
            method: 'POST',
            body: JSON.stringify(formData)
        });
        showMessage('グループができました！メンバーを追加してね。');
        await loadGroup(data.code);
    } catch (error) {
        showMessage(error.message, 'error');
        showView('home');
    }
});

document.getElementById('join-group-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const code = event.target.code.value.trim();
    if (!code) {
        showMessage('コードを入れてね。', 'error');
        return;
    }
    await loadGroup(code);
});

document.getElementById('add-member-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.code) return;

    const formData = Object.fromEntries(new FormData(event.target));
    if (!formData.name) {
        showMessage('お名前を入れてね。', 'error');
        return;
    }

    const payload = {
        code: state.code,
        name: formData.name,
        role: formData.role,
        default_ratio: Number(formData.default_ratio || 1)
    };

    try {
        await apiRequest('api/groups.php?action=add_member', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        showMessage('メンバーを追加しました。');
        event.target.reset();
        await loadGroup(state.code);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

document.getElementById('add-item-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.code) return;

    const form = event.target;
    const formData = Object.fromEntries(new FormData(form));
    const participantIds = gatherParticipants();

    if (!participantIds.length) {
        showMessage('参加した人を選んでね。', 'error');
        return;
    }

    const payload = {
        code: state.code,
        title: formData.title,
        amount: Number(formData.amount),
        payer_member_id: Number(formData.payer_member_id),
        participant_ids: participantIds.map(Number)
    };

    if (!payload.title) {
        showMessage('内容を入れてね。', 'error');
        return;
    }

    if (!payload.amount || payload.amount <= 0) {
        showMessage('金額を正しく入れてね。', 'error');
        return;
    }

    if (!payload.payer_member_id) {
        showMessage('払った人を選んでね。', 'error');
        return;
    }

    try {
        await apiRequest('api/items.php?action=add_item', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        showMessage('支出を記録しました。');
        resetItemForm();
        await fetchItems();
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

document.getElementById('calculate-settlement').addEventListener('click', async () => {
    if (!state.code) return;
    try {
        const data = await apiRequest(`api/settle.php?action=calculate&code=${encodeURIComponent(state.code)}`);
        renderSettlement(data);
        showMessage('精算を計算しました。');
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

document.getElementById('back-home').addEventListener('click', () => {
    state.group = null;
    state.members = [];
    state.items = [];
    updateUrlCodeParam(null);
    showView('home');
});

document.getElementById('share-link').addEventListener('click', async () => {
    if (!state.group) return;
    const url = buildGroupUrl(state.group.code);
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(url);
            showMessage('リンクをコピーしました。みんなに教えてね。');
        } catch (error) {
            showMessage('コピーに失敗しました。', 'error');
        }
    } else {
        prompt('このリンクをコピーして共有してください', url);
    }
});

window.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');
    if (code) {
        loadGroup(code);
    } else {
        showView('home');
    }
});
