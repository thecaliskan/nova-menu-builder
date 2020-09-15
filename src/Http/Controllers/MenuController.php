<?php

namespace OptimistDigital\MenuBuilder\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest;
use OptimistDigital\MenuBuilder\MenuBuilder;
use OptimistDigital\MenuBuilder\Models\Menu;
use OptimistDigital\MenuBuilder\Models\MenuItem;

class MenuController extends Controller
{
    /**
     * Return root menu items for one menu.
     *
     * @param Illuminate\Http\Request $request
     * @param OptimistDigital\MenuBuilder\Models\Menu $menu
     * @return Illuminate\Http\Response
     **/
    public function getMenuItems(Request $request, Menu $menu)
    {
        if (empty($menu)) return response()->json(['menu' => 'menu_not_found'], 400);

        $menuItems = $menu->rootMenuItems->filter(function ($item) {
            return class_exists($item->class);
        });

        return response()->json($menuItems, 200);
    }

    /**
     * Save menu items.
     *
     * @param Illuminate\Http\Request $request
     * @param OptimistDigital\MenuBuilder\Models\Menu $menu
     * @return Illuminate\Http\Response
     **/
    public function saveMenuItems(Request $request, Menu $menu)
    {
        $items = $request->get('menuItems');

        $i = 1;
        foreach ($items as $item) {
            $this->saveMenuItemWithNewOrder($i, $item);
            $i++;
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Creates new MenuItem.
     *
     * @param OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest $request
     * @return Illuminate\Http\Response
     **/
    public function createMenuItem(MenuItemFormRequest $request)
    {
        $menuItemModel = MenuBuilder::getMenuItemClass();

        $request->validate([
            'class' => 'required',
            'value' => 'present',
            'enabled' => 'present',
            'name' => 'required|min:1',
        ]);

        $data = $request->getValues();
        $data['order'] = $menuItemModel::max('id') + 1;

        // Add fail-safe due to https://github.com/optimistdigital/nova-menu-builder/issues/41
        $data['parameters'] = empty($data['parameters']) ? null : $data['parameters'];

        $model = new $menuItemModel;
        foreach ($data as $key => $value) {
            $model->{$key} = $value;
        }
        $model->save();

        return response()->json(['success' => true], 200);
    }

    /**
     * Returns the menu item as JSON.
     *
     * @param OptimistDigital\MenuBuilder\Models\MenuItem $menuItem
     * @return Illuminate\Http\Response
     **/
    public function getMenuItem(MenuItem $menuItem)
    {
        return isset($menuItem)
            ? response()->json($menuItem, 200)
            : resonse()->json(['error' => 'item_not_found'], 400);
    }

    /**
     * Updates a MenuItem.
     *
     * @param OptimistDigital\MenuBuilder\Http\Requests\MenuItemFormRequest $request
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function updateMenuItem(MenuItemFormRequest $request, $menuItemId)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemId);

        /** @var MenuItem $menuItem */
        if (!isset($menuItem)) return response()->json(['error' => 'menu_item_not_found'], 400);
        $data = $request->getValues();

        // Add fail-safe due to https://github.com/optimistdigital/nova-menu-builder/issues/47
        $data['parameters'] = empty($data['parameters']) ? null : $data['parameters'];

        foreach ($data as $key => $value) {
            $menuItem->{$key} = $value;
        }

        $menuItem->save();
        return response()->json(['success' => true], 200);
    }

    /**
     * Deletes a MenuItem.
     *
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function deleteMenuItem($menuItemId)
    {
        /** @var MenuItem $menuItem */
        $menuItem = MenuBuilder::getMenuItemClass()::findOrFail($menuItemId);

        $menuItem->children()->delete();
        $menuItem->delete();
        return response()->json(['success' => true], 200);
    }

    /**
     * Get link types for locale.
     *
     * @param string $locale
     * @return Illuminate\Http\Response
     **/
    public function getMenuItemTypes($locale)
    {
        $menuItemTypes = [];
        $menuItemTypesRaw = MenuBuilder::getMenuItemTypes();

        foreach ($menuItemTypesRaw as $typeClass) {
            if (!class_exists($typeClass)) continue;

            $data = [
                'name' => $typeClass::getName(),
                'type' => $typeClass::getType(),
                'fields' => MenuBuilder::getFieldsFromMenuLinkable($typeClass) ?? [],
                'class' => $typeClass,
            ];


            if (method_exists($typeClass, 'getOptions')) {
                $data['options'] = $typeClass::getOptions($locale);
            }

            $menuItemTypes[] = $data;
        }

        return response()->json($menuItemTypes, 200);
    }

    /**
     * Duplicates a MenuItem.
     *
     * @param $menuItem
     * @return Illuminate\Http\Response
     **/
    public function duplicateMenuItem($menuItemId)
    {
        /** @var MenuItem $menuItem */
        $menuItem = MenuBuilder::getMenuItemClass()::find($menuItemId);

        if (empty($menuItem)) return response()->json(['error' => 'menu_item_not_found'], 400);

        $this->shiftMenuItemsWithHigherOrder($menuItem);
        $this->recursivelyDuplicate($menuItem, $menuItem->parent_id, $menuItem->order + 1);

        return response()->json(['success' => true], 200);
    }


    // ------------------------------
    // Helpers
    // ------------------------------

    /**
     * Increase order number of every menu item that has higher order number than ours by one
     *
     * @param MenuItem $menuItem
     */
    private function shiftMenuItemsWithHigherOrder($menuItem)
    {
        $tableName = $menuItem->getTable();
        $menuItemParentSql = $menuItem->parent_id ? "menuItem.parent_id = $menuItem->parent_id" : 'menuItem.parent_id IS NULL';

        DB::statement(
            <<<SQL
                UPDATE $tableName AS menuItem
                SET menuItem.order = menuItem.order + 1
                WHERE menuItem.order > {$menuItem->order}
                AND menuItem.menu_id = {$menuItem->menu_id}
                AND {$menuItemParentSql}
SQL
        );
    }

    private function recursivelyOrderChildren($item)
    {
        if (count($item['children']) > 0) {
            foreach ($item['children'] as $i => $child) {
                $this->saveMenuItemWithNewOrder($i + 1, $child, $item['id']);
            }
        }
    }

    private function saveMenuItemWithNewOrder($orderNr, $item, $parentId = null)
    {
        $menuItem = MenuBuilder::getMenuItemClass()::find($item['id']);
        $menuItem->order = $orderNr;
        $menuItem->parent_id = $parentId;
        $menuItem->save();

        // Check children
        if (count($item['children']) > 0) {
            foreach ($item['children'] as $i => $child) {
                $this->saveMenuItemWithNewOrder($i + 1, $child, $item['id']);
            }
        }

        $this->recursivelyOrderChildren($item);
    }

    protected function recursivelyDuplicate(MenuItem $item, $parentId = null, $order = null)
    {
        $data = $item->toArray();
        unset($data['id']);
        if ($parentId != null) $data['parent_id'] = $parentId;
        if ($order != null) $data['order'] = $order;
        $newItem = MenuBuilder::getMenuItemClass()::create($data);
        $children = $item->children;
        foreach ($children as $child) $this->recursivelyDuplicate($child, $newItem->id);
    }
}
