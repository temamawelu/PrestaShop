<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShopBundle\Service\DataProvider\Admin;

use PrestaShop\PrestaShop\Core\Addon\Module\ModuleInterface;
use PrestaShop\PrestaShop\Core\Module\ModuleCollection;
use stdClass;

/**
 * Provide the categories used to order modules and themes on https://addons.prestashop.com.
 */
class CategoriesProvider
{
    public const CATEGORY_OTHER = 'other';
    public const CATEGORY_OTHER_NAME = 'Other';

    public const CATEGORY_THEME = 'theme_modules';
    public const CATEGORY_THEME_NAME = 'Theme modules';

    /**
     * @var array
     */
    private $modulesTheme;

    /**
     * @var array
     */
    private $categories;

    /**
     * @var array
     */
    private $categoriesMenu;

    /**
     * @var object
     */
    private $categoriesFromSource;

    public function __construct(array $addonsCategories, array $modulesTheme)
    {
        $this->modulesTheme = $modulesTheme;
        // We now avoid calling the API. This data is loaded from a local YML file
        $this->categoriesFromSource = $this->sortCategories($addonsCategories);
        $this->categories = $this->initializeCategories($this->categoriesFromSource);
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * Return the list of categories with the associated modules.
     *
     * @param array|ModuleCollection $modules
     *
     * @return array the list of categories
     */
    public function getCategoriesMenu($modules): array
    {
        if (null === $this->categoriesMenu) {
            // The Root category is "Categories"
            // Co py the original array
            $categories = $this->categories;
            foreach ($modules as $module) {
                $category = $this->findModuleCategory($module, $categories);
                $categories['categories']->subMenu[$category]->modules[] = $module;
            }

            // Clear custom categories if there is no module inside
            if (empty($categories['categories']->subMenu[self::CATEGORY_THEME]->modules)) {
                unset($categories['categories']->subMenu[self::CATEGORY_THEME]);
            }

            $this->categoriesMenu = $categories;
        }

        return $this->categoriesMenu;
    }

    /**
     * Initialize categories from API or if this one is empty,
     * use theme and my modules categories.
     *
     * @param object $categoriesListing Category listing
     *
     * @return array<string, stdClass>
     */
    private function initializeCategories($categoriesListing)
    {
        $categories = [
            'categories' => $this->createMenuObject('categories', 'Categories'),
        ];

        foreach ($categoriesListing as $category) {
            $categories['categories']->subMenu[$category->name] = $this->createMenuObject(
                $category->id_category,
                $category->name,
                [],
                isset($category->tab) ? $category->tab : null
            );
        }

        $categories['categories']->subMenu[self::CATEGORY_THEME] = $this->createMenuObject(
            self::CATEGORY_THEME,
            self::CATEGORY_THEME_NAME,
            [],
            self::CATEGORY_THEME
        );
        $categories['categories']->subMenu[self::CATEGORY_OTHER] = $this->createMenuObject(
            self::CATEGORY_OTHER,
            self::CATEGORY_OTHER_NAME,
            [],
            self::CATEGORY_OTHER
        );

        return $categories;
    }

    /**
     * Considering a category name, return his category parent name.
     *
     * @param string $categoryName
     *
     * @return string
     */
    public function getParentCategory(string $categoryName): string
    {
        foreach ($this->categoriesFromSource as $parentCategory) {
            foreach ($parentCategory->categories as $childCategory) {
                if ($childCategory->name === $categoryName) {
                    return $parentCategory->name;
                }
            }
        }

        return $categoryName;
    }

    /**
     * Re-organize category data into a Menu item.
     *
     * @param string $menu
     * @param string $name
     * @param array $moduleIds
     * @param string $tab
     *
     * @return stdClass
     */
    private function createMenuObject(string $menu, string $name, array $moduleIds = [], ?string $tab = null): stdClass
    {
        return (object) [
            'tab' => $tab,
            'name' => $name,
            'refMenu' => $menu,
            'modules' => $moduleIds,
            'subMenu' => [],
        ];
    }

    /**
     * Find module category.
     *
     * @param ModuleInterface $installedProduct Installed product
     * @param array $categories Available categories
     */
    private function findModuleCategory(ModuleInterface $installedProduct, array $categories): string
    {
        $moduleCategoryParent = $installedProduct->attributes->get('categoryParentEnglishName');
        if (!isset($categories['categories']->subMenu[$moduleCategoryParent])) {
            if (in_array($installedProduct->attributes->get('name'), $this->modulesTheme)) {
                $moduleCategoryParent = self::CATEGORY_THEME;
            } else {
                $moduleCategoryParent = $this->getParentCategoryFromTabAttribute($installedProduct);
            }
        }

        if (array_key_exists($moduleCategoryParent, $categories['categories']->subMenu)) {
            return $moduleCategoryParent;
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * Sort addons categories by order field.
     *
     * @param array $categories
     *
     * @return stdClass
     */
    private function sortCategories(array $categories): stdClass
    {
        uasort(
            $categories,
            function ($a, $b) {
                return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
            }
        );

        // Convert array to object to be consistent with current API call
        return json_decode(json_encode($categories));
    }

    /**
     * Try to find the parent category depending on
     * the module's tab attribute.
     *
     * @param ModuleInterface $module
     *
     * @return ?string
     */
    private function getParentCategoryFromTabAttribute(ModuleInterface $module): ?string
    {
        foreach ($this->categoriesFromSource as $parentCategory) {
            foreach ($parentCategory->categories as $category) {
                if (isset($category->tab) && $category->tab === $module->attributes->get('tab')) {
                    return $parentCategory->name;
                }
            }
        }

        return null;
    }
}
