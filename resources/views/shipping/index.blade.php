@extends('layouts.app')

@section('title', 'Shipping Methods')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-ink">Shipping Methods</h1>
            <button onclick="openCreateModal()" class="bg-primary-700 hover:bg-primary-600 text-white px-6 py-2 rounded-lg transition">
                Add Shipping Method
            </button>
        </div>

        @if(session('success'))
            <div class="bg-primary-100 border border-primary-600 text-primary-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-danger-50 border border-danger-600 text-danger-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline">{{ session('error') }}</span>
            </div>
        @endif

        <div class="bg-white rounded-lg overflow-hidden border border-hairline">
            <table class="min-w-full divide-y divide-hairline">
                <thead class="bg-surface">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Base Rate</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Weight Rate</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Max Weight</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Delivery Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-muted uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-hairline">
                    @forelse($shippingMethods as $method)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-ink">{{ $method->name }}</div>
                                @if($method->description)
                                    <div class="text-sm text-muted">{{ Str::limit($method->description, 50) }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-ink">
                                ${{ number_format($method->base_rate, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-ink">
                                ${{ number_format($method->weight_rate, 2) }}/lb
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-ink">
                                {{ $method->max_weight ? number_format($method->max_weight, 2) . ' lb' : 'No limit' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-ink">
                                {{ $method->estimated_delivery_time ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $method->is_active ? 'bg-primary-100 text-primary-800' : 'bg-danger-50 text-danger-700' }}">
                                    {{ $method->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button onclick="openEditModal({{ $method->id }}, '{{ $method->name }}', '{{ $method->description }}', {{ $method->base_rate }}, {{ $method->weight_rate }}, {{ $method->max_weight ?? 'null' }}, '{{ $method->estimated_delivery_time }}', {{ $method->is_active ? 'true' : 'false' }})" 
                                        class="text-primary-700 hover:text-primary-800 mr-3">Edit</button>
                                <form action="{{ route('shipping.destroy', $method) }}" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this shipping method?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-danger-600 hover:text-danger-700">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-muted">
                                No shipping methods found. Click "Add Shipping Method" to create one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div id="createModal" class="hidden fixed inset-0 bg-ink bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border border-hairline w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-ink">Add Shipping Method</h3>
            <button onclick="closeCreateModal()" class="text-muted hover:text-muted">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form action="{{ route('shipping.store') }}" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-ink">Name *</label>
                    <input type="text" name="name" required class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                </div>
                <div>
                    <label class="block text-sm font-medium text-ink">Description</label>
                    <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-ink">Base Rate ($) *</label>
                        <input type="number" name="base_rate" step="0.01" min="0" required class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Weight Rate ($/lb)</label>
                        <input type="number" name="weight_rate" step="0.01" min="0" value="0" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-ink">Max Weight (lb)</label>
                        <input type="number" name="max_weight" step="0.01" min="0" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Estimated Delivery Time *</label>
                        <input type="text" name="estimated_delivery_time" required placeholder="e.g., 3-5 business days" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-stroke text-primary-700 focus:border-primary-600 focus:ring-primary-600">
                        <span class="ml-2 text-sm text-ink">Active</span>
                    </label>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeCreateModal()" class="px-4 py-2 bg-hairline text-ink rounded-md hover:bg-stroke">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary-700 text-white rounded-md hover:bg-primary-600">
                    Create
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-ink bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border border-hairline w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-ink">Edit Shipping Method</h3>
            <button onclick="closeEditModal()" class="text-muted hover:text-muted">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form id="editForm" method="POST">
            @csrf
            @method('PUT')
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-ink">Name *</label>
                    <input type="text" id="edit_name" name="name" required class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                </div>
                <div>
                    <label class="block text-sm font-medium text-ink">Description</label>
                    <textarea id="edit_description" name="description" rows="3" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-ink">Base Rate ($) *</label>
                        <input type="number" id="edit_base_rate" name="base_rate" step="0.01" min="0" required class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Weight Rate ($/lb)</label>
                        <input type="number" id="edit_weight_rate" name="weight_rate" step="0.01" min="0" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-ink">Max Weight (lb)</label>
                        <input type="number" id="edit_max_weight" name="max_weight" step="0.01" min="0" class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Estimated Delivery Time *</label>
                        <input type="text" id="edit_estimated_delivery_time" name="estimated_delivery_time" required class="mt-1 block w-full rounded-md border-stroke focus:border-primary-600 focus:ring-primary-600">
                    </div>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" id="edit_is_active" name="is_active" value="1" class="rounded border-stroke text-primary-700 focus:border-primary-600 focus:ring-primary-600">
                        <span class="ml-2 text-sm text-ink">Active</span>
                    </label>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-hairline text-ink rounded-md hover:bg-stroke">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary-700 text-white rounded-md hover:bg-primary-600">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    function openCreateModal() {
        document.getElementById('createModal').classList.remove('hidden');
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.add('hidden');
    }

    function openEditModal(id, name, description, baseRate, weightRate, maxWeight, deliveryTime, isActive) {
        document.getElementById('editForm').action = '/shipping/' + id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_description').value = description || '';
        document.getElementById('edit_base_rate').value = baseRate;
        document.getElementById('edit_weight_rate').value = weightRate;
        document.getElementById('edit_max_weight').value = maxWeight || '';
        document.getElementById('edit_estimated_delivery_time').value = deliveryTime || '';
        document.getElementById('edit_is_active').checked = isActive;
        document.getElementById('editModal').classList.remove('hidden');
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const createModal = document.getElementById('createModal');
        const editModal = document.getElementById('editModal');
        if (event.target == createModal) {
            closeCreateModal();
        }
        if (event.target == editModal) {
            closeEditModal();
        }
    }
</script>
@endpush
@endsection
