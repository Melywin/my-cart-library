<?php

namespace MyCartLibrary;

use Config\Services;

class Cart
{
    public string $productIdRules = '\.a-z0-9_-';
    public string $productNameRules = '\w \-\.\:\%\,\&';
    public bool $productNameSafe = true;

    protected array $cartContents = [];
    protected Session $session;

    public function __construct()
    {
        $this->session = Services::session();

        $this->cartContents = $this->session->get('cart_contents') ?? ['cart_total' => 0, 'total_items' => 0];

        log_message('info', 'Cart Library Initialized'); 
    }

    public function insert(array $items): bool|string
    {
        if (empty($items)) {
            log_message('error', 'Insert method requires an array.');
            return false;
        }

        $saveCart = false;
        $rowid = null;

        if (isset($items['id'])) {
            $rowid = $this->_insert($items);
            $saveCart = (bool)$rowid;
        } else {
            foreach ($items as $item) {
                if (isset($item['id']) && is_array($item)) {
                    $rowid = $this->_insert($item);
                    $saveCart = true;
                }
            }
        }

        if ($saveCart) {
            $this->saveCart();
        }

        return $rowid ?? false;
    }

    protected function _insert(array $items): bool|string
    {
        if (empty($items) || !isset($items['id'], $items['qty'], $items['price'], $items['name'])) {
            log_message('error', 'Product must include ID, quantity, price, and name.');
            return false;
        }

        $items['qty'] = max((float)$items['qty'], 0);
        if ($items['qty'] === 0) {
            return false;
        }

        if (!preg_match('/^[' . $this->productIdRules . ']+$/i', $items['id'])) {
            log_message('error', 'Invalid product ID format.');
            return false;
        }

        if ($this->productNameSafe && !preg_match('/^[' . $this->productNameRules . ']+$/iu', $items['name'])) {
            log_message('error', 'Invalid product name.');
            return false;
        }

        $items['price'] = (float)$items['price'];

        $rowid = isset($items['options']) && !empty($items['options'])
            ? md5($items['id'] . serialize($items['options']))
            : md5($items['id']);

        $oldQty = $this->cartContents[$rowid]['qty'] ?? 0;

        $items['qty'] = $items['qty'] + $oldQty;

        if (isset($items['max_ord']) && $items['max_ord'] > 0 && $items['qty'] > $items['max_ord']) {
            log_message('error', 'Maximum order limit exceeded.');
            return false;
        }

        $items['rowid'] = $rowid;
        $this->cartContents[$rowid] = $items;

        return $rowid;
    }

    public function update(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        $saveCart = false;

        if (isset($items['rowid'])) {
            $saveCart = $this->_update($items);
        } else {
            foreach ($items as $item) {
                if (isset($item['rowid']) && is_array($item)) {
                    $saveCart = $this->_update($item);
                }
            }
        }

        if ($saveCart) {
            $this->saveCart();
        }

        return $saveCart;
    }

    protected function _update(array $items): bool
    {
        if (!isset($items['rowid'], $this->cartContents[$items['rowid']])) {
            return false;
        }

        if (isset($items['qty']) && $items['qty'] === 0) {
            unset($this->cartContents[$items['rowid']]);
            return true;
        }

        $keys = array_intersect(array_keys($this->cartContents[$items['rowid']]), array_keys($items));
        foreach ($keys as $key) {
            if ($key !== 'id' && $key !== 'name') {
                $this->cartContents[$items['rowid']][$key] = $items[$key];
            }
        }

        return true;
    }

    protected function saveCart(): bool
    {
        $this->cartContents['total_items'] = $this->cartContents['cart_total'] = 0;

        foreach ($this->cartContents as $key => $val) {
            if (!is_array($val) || !isset($val['price'], $val['qty'])) {
                continue;
            }

            $this->cartContents['cart_total'] += $val['price'] * $val['qty'];
            $this->cartContents['total_items'] += $val['qty'];
            $this->cartContents[$key]['subtotal'] = $val['price'] * $val['qty'];
        }

        if (count($this->cartContents) <= 2) {
            $this->session->remove('cart_contents');
            return false;
        }

        $this->session->set('cart_contents', $this->cartContents);
        return true;
    }

    public function total(): float
    {
        return $this->cartContents['cart_total'];
    }

    public function remove(string $rowid): bool
    {
        unset($this->cartContents[$rowid]);
        $this->saveCart();
        return true;
    }

    public function totalItems(): int
    {
        return (int)$this->cartContents['total_items'];
    }

    public function contents(bool $newestFirst = false): array
    {
        $cart = $newestFirst ? array_reverse($this->cartContents) : $this->cartContents;
        unset($cart['total_items'], $cart['cart_total']);
        return $cart;
    }

    public function destroy(): void
    {
        $this->cartContents = ['cart_total' => 0, 'total_items' => 0];
        $this->session->remove('cart_contents');
    }
}
