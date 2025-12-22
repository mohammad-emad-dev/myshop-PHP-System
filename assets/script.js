function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

window.onclick = function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

function editProduct(product) {
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_name').value = product.name;
    document.getElementById('edit_description').value = product.description;
    document.getElementById('edit_price').value = product.price;
    document.getElementById('edit_stock').value = product.stock;
    openModal('editProductModal');
}

const searchInput = document.getElementById('searchProduct');
if (searchInput) {
    searchInput.addEventListener('keyup', function () {
        const filter = this.value.toLowerCase();
        const table = document.getElementById('productsTable');
        const rows = table.getElementsByTagName('tr');

        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;

            for (let j = 0; j < cells.length; j++) {
                const cell = cells[j];
                if (cell) {
                    const textValue = cell.textContent || cell.innerText;
                    if (textValue.toLowerCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }

            rows[i].style.display = found ? '' : 'none';
        }
    });
}

function addOrderItem() {
    const container = document.getElementById('orderItems');
    const template = container.querySelector('.order-item').cloneNode(true);

    const select = template.querySelector('.product-select');
    const input = template.querySelector('.quantity-input');
    select.value = '';
    input.value = 1;

    container.appendChild(template);
    calculateTotal();
}

function removeOrderItem(button) {
    const container = document.getElementById('orderItems');
    const items = container.querySelectorAll('.order-item');

    if (items.length > 1) {
        button.closest('.order-item').remove();
        calculateTotal();
    } else {
        alert('You must have at least one item in the order');
    }
}

function calculateTotal() {
    const items = document.querySelectorAll('.order-item');
    let total = 0;

    items.forEach(item => {
        const select = item.querySelector('.product-select');
        const quantityInput = item.querySelector('.quantity-input');

        if (select && quantityInput) {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const price = parseFloat(selectedOption.dataset.price || 0);
                const quantity = parseInt(quantityInput.value || 0);
                total += price * quantity;
            }
        }
    });

    const totalElement = document.getElementById('orderTotal');
    if (totalElement) {
        totalElement.textContent = total.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const productSelects = document.querySelectorAll('.product-select');
    productSelects.forEach(select => {
        select.addEventListener('change', calculateTotal);
    });

    const quantityInputs = document.querySelectorAll('.quantity-input');
    quantityInputs.forEach(input => {
        input.addEventListener('input', calculateTotal);
    });

    calculateTotal();
});
