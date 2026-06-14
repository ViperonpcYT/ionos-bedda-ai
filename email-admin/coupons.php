<?php
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/functions.php';
requireLogin();

define('ADMIN_DASHBOARD_INCLUDED', true);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Coupons - OnlyBikes Email Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sage: {
                            100: '#dcfce7',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                        },
                    },
                },
            },
        };
    </script>
</head>
<body class="bg-stone-50 min-h-screen">
    <?php renderNav('coupons'); ?>
    
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Coupon Codes Section -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
                <h2 class="text-lg font-bold text-stone-800">Coupon Codes</h2>
                <div class="flex flex-wrap gap-2">
                <button type="button" onclick="backfillCouponEconomics()"
                    class="inline-flex items-center gap-2 border border-stone-300 text-stone-700 px-4 py-2.5 rounded-lg hover:bg-stone-50 text-sm font-medium shrink-0">
                    Sync spent/saved from Stripe
                </button>
                <button type="button" onclick="openCouponModal()" id="add-coupon-btn"
                    class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2.5 rounded-lg hover:bg-green-700 text-sm font-semibold shadow-sm border border-green-700 shrink-0">
                    <span aria-hidden="true">+</span> Add Coupon
                </button>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex space-x-2 mb-4 border-b border-stone-200 pb-2">
                <button class="filter-tab px-3 py-1 text-sm font-medium rounded bg-sage-100 text-sage-800" data-filter="all">All</button>
                <button class="filter-tab px-3 py-1 text-sm font-medium rounded text-stone-600 hover:bg-stone-100" data-filter="active">Active</button>
                <button class="filter-tab px-3 py-1 text-sm font-medium rounded text-stone-600 hover:bg-stone-100" data-filter="inactive">Inactive</button>
                <button class="filter-tab px-3 py-1 text-sm font-medium rounded text-stone-600 hover:bg-stone-100" data-filter="deleted">Deleted</button>
            </div>

            <!-- Coupons Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50">
                        <tr>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Code</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Type</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Value</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Min Order</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Usage</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Economics</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Expires</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Status</th>
                            <th class="text-left py-3 px-4 font-medium text-stone-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="coupons-table-body" class="divide-y divide-stone-100">
                        <tr><td colspan="9" class="py-8 text-center text-stone-500">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div id="coupon-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-playfair text-xl font-bold text-stone-800" id="modal-title">Add Coupon</h3>
                    <button onclick="closeCouponModal()" class="text-stone-400 hover:text-stone-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form id="coupon-form" class="space-y-4">
                    <input type="hidden" name="id" id="coupon-id">
                    <input type="hidden" name="action" id="coupon-action" value="create">
                    
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Code *</label>
                        <input type="text" name="code" id="coupon-code" required class="w-full px-3 py-2 border border-stone-300 rounded uppercase" placeholder="FAMILYDISCOUNT1" maxlength="50">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Type *</label>
                            <select name="type" id="coupon-type" required class="w-full px-3 py-2 border border-stone-300 rounded">
                                <option value="percent">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ($)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Value *</label>
                            <input type="number" name="value" id="coupon-value" required step="0.01" min="0.01" class="w-full px-3 py-2 border border-stone-300 rounded" placeholder="10">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Minimum Order Total ($)</label>
                        <input type="number" name="min_total" id="coupon-min-total" step="0.01" min="0" class="w-full px-3 py-2 border border-stone-300 rounded" placeholder="0">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Expires At</label>
                            <input type="datetime-local" name="expires_at" id="coupon-expires" class="w-full px-3 py-2 border border-stone-300 rounded">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-1">Usage Limit</label>
                            <input type="number" name="usage_limit" id="coupon-usage-limit" min="1" class="w-full px-3 py-2 border border-stone-300 rounded" placeholder="Unlimited">
                        </div>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="active" id="coupon-active" checked class="h-4 w-4 text-sage-600 border-stone-300 rounded">
                        <label for="coupon-active" class="ml-2 text-sm text-stone-700">Active</label>
                    </div>
                    
                    <div class="flex space-x-3 pt-4">
                        <button type="button" onclick="closeCouponModal()" class="flex-1 border border-stone-300 text-stone-700 py-2 rounded hover:bg-stone-50">Cancel</button>
                        <button type="submit" class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 font-semibold">Save Coupon</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    let currentFilter = 'all';

    document.addEventListener('DOMContentLoaded', function() {
        loadCoupons();
        
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => {
                    t.classList.remove('bg-sage-100', 'text-sage-800');
                    t.classList.add('text-stone-600');
                });
                this.classList.remove('text-stone-600');
                this.classList.add('bg-sage-100', 'text-sage-800');
                currentFilter = this.dataset.filter;
                loadCoupons();
            });
        });
        
        document.getElementById('coupon-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            try {
                const response = await fetch('../api/manage-coupons.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    closeCouponModal();
                    loadCoupons();
                    alert('Coupon saved successfully');
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (err) {
                alert('Network error. Please try again.');
            }
        });
    });

    async function backfillCouponEconomics() {
        if (!confirm('Scan recent orders in Stripe and fill coupon_code / discount on orders that are missing it?')) {
            return;
        }
        try {
            const fd = new FormData();
            fd.append('action', 'backfill_economics');
            const response = await fetch('../api/manage-coupons.php', { method: 'POST', body: fd });
            const result = await response.json();
            if (result.success) {
                const r = result.report || {};
                alert(`Done. Updated ${r.updated || 0} order(s), scanned ${r.scanned || 0}.`);
                loadCoupons();
            } else {
                alert('Error: ' + (result.message || 'Backfill failed'));
            }
        } catch (e) {
            alert('Network error during backfill.');
        }
    }

    async function loadCoupons() {
        const tbody = document.getElementById('coupons-table-body');
        tbody.innerHTML = '<tr><td colspan="9" class="py-8 text-center text-stone-500">Loading...</td></tr>';
        
        try {
            const response = await fetch('../api/manage-coupons.php?action=list');
            const result = await response.json();
            
            if (!result.success) {
                tbody.innerHTML = '<tr><td colspan="9" class="py-8 text-center text-red-600">Error loading coupons</td></tr>';
                return;
            }
            
            let coupons = result.data;
            if (currentFilter === 'active') coupons = coupons.filter(c => c.active && !c.deleted);
            else if (currentFilter === 'inactive') coupons = coupons.filter(c => !c.active && !c.deleted);
            else if (currentFilter === 'deleted') coupons = coupons.filter(c => c.deleted);
            
            if (coupons.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="py-10 text-center">
                            <p class="text-stone-500 mb-4">${currentFilter === 'all' ? 'No coupons yet.' : 'No coupons in this filter.'}</p>
                            <button type="button" onclick="openCouponModal()"
                                class="inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2.5 rounded-lg hover:bg-green-700 text-sm font-semibold shadow-sm border border-green-700">
                                + Add Coupon
                            </button>
                        </td>
                    </tr>`;
                return;
            }
            
            tbody.innerHTML = coupons.map(c => {
                const status = c.deleted ? '<span class="text-stone-400">Deleted</span>' : 
                              c.active ? '<span class="text-green-600 font-medium">Active</span>' : 
                              '<span class="text-sage-600 font-medium">Inactive</span>';
                const expires = c.expires_at ? new Date(c.expires_at).toLocaleDateString() : 'Never';
                const usedN = c.used_count_display != null ? c.used_count_display : (c.usage_orders != null ? c.usage_orders : c.used_count);
                const usage = c.usage_limit ? `${usedN}/${c.usage_limit}` : `${usedN} used`;
                const spent = parseFloat(c.total_spent || 0);
                const saved = parseFloat(c.total_saved || 0);
                const economics = `<span class="text-stone-700">$${spent.toFixed(2)}</span> <span class="text-stone-400">spent</span> · <span class="text-green-700">$${saved.toFixed(2)}</span> <span class="text-stone-400">saved</span>`;
                
                return `
                    <tr class="hover:bg-stone-50">
                        <td class="py-3 px-4 font-mono text-sm">${escapeHtml(c.code)}</td>
                        <td class="py-3 px-4">${c.type === 'percent' ? '%' : '$'}</td>
                        <td class="py-3 px-4">${parseFloat(c.value).toFixed(2)}</td>
                        <td class="py-3 px-4">$${parseFloat(c.min_total).toFixed(2)}</td>
                        <td class="py-3 px-4">${usage}</td>
                        <td class="py-3 px-4 text-xs whitespace-nowrap">${economics}</td>
                        <td class="py-3 px-4">${expires}</td>
                        <td class="py-3 px-4">${status}</td>
                        <td class="py-3 px-4 space-x-2">
                            ${!c.deleted ? `<button onclick="editCoupon(${c.id})" class="text-blue-600 hover:text-blue-800 text-xs">Edit</button>` : ''}
                            ${!c.deleted ? `<button onclick="toggleCoupon(${c.id}, ${c.active ? 0 : 1})" class="text-sage-600 hover:text-sage-800 text-xs">${c.active ? 'Deactivate' : 'Activate'}</button>` : ''}
                            ${!c.deleted ? `<button onclick="deleteCoupon(${c.id})" class="text-red-600 hover:text-red-800 text-xs">Delete</button>` : ''}
                            ${c.deleted ? `<button onclick="restoreCoupon(${c.id})" class="text-green-600 hover:text-green-800 text-xs">Restore</button>` : ''}
                        </td>
                    </tr>
                `;
            }).join('');
            
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="9" class="py-8 text-center text-red-600">Error loading coupons</td></tr>';
        }
    }

    function openCouponModal(coupon = null) {
        const modal = document.getElementById('coupon-modal');
        const title = document.getElementById('modal-title');
        const form = document.getElementById('coupon-form');
        
        if (coupon) {
            title.textContent = 'Edit Coupon';
            document.getElementById('coupon-action').value = 'update';
            document.getElementById('coupon-id').value = coupon.id;
            document.getElementById('coupon-code').value = coupon.code;
            document.getElementById('coupon-type').value = coupon.type;
            document.getElementById('coupon-value').value = coupon.value;
            document.getElementById('coupon-min-total').value = coupon.min_total;
            document.getElementById('coupon-expires').value = coupon.expires_at ? coupon.expires_at.slice(0, 16) : '';
            document.getElementById('coupon-usage-limit').value = coupon.usage_limit || '';
            document.getElementById('coupon-active').checked = coupon.active;
        } else {
            title.textContent = 'Add Coupon';
            document.getElementById('coupon-action').value = 'create';
            form.reset();
            document.getElementById('coupon-id').value = '';
            document.getElementById('coupon-active').checked = true;
        }
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeCouponModal() {
        const modal = document.getElementById('coupon-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function editCoupon(id) {
        fetch('../api/manage-coupons.php?action=list')
            .then(r => r.json())
            .then(result => {
                const coupon = result.data.find(c => c.id === id);
                if (coupon) openCouponModal(coupon);
            });
    }

    async function toggleCoupon(id, newActive) {
        if (!confirm('Are you sure?')) return;
        
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', id);
        
        const response = await fetch('../api/manage-coupons.php?action=list');
        const result = await response.json();
        const coupon = result.data.find(c => c.id === id);
        if (!coupon) return;
        
        formData.append('code', coupon.code);
        formData.append('type', coupon.type);
        formData.append('value', coupon.value);
        formData.append('min_total', coupon.min_total);
        formData.append('expires_at', coupon.expires_at || '');
        formData.append('usage_limit', coupon.usage_limit || '');
        formData.append('active', newActive ? '1' : '0');
        
        const res = await fetch('../api/manage-coupons.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) loadCoupons();
        else alert('Error: ' + data.message);
    }

    async function deleteCoupon(id) {
        if (!confirm('Delete this coupon? It can be restored later.')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('../api/manage-coupons.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) loadCoupons();
        else alert('Error: ' + result.message);
    }

    async function restoreCoupon(id) {
        if (!confirm('Restore this coupon?')) return;
        
        const formData = new FormData();
        formData.append('action', 'restore');
        formData.append('id', id);
        
        const response = await fetch('../api/manage-coupons.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) loadCoupons();
        else alert('Error: ' + result.message);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>