#include "otpch.h"

#include "itemevolution.h"

#include <algorithm>
#include <string_view>
#include <type_traits>
#include <vector>

#include <fmt/format.h>

#include "combat.h"
#include "configmanager.h"
#include "item.h"
#include "player.h"
#include "tools.h"

ItemEvolution g_itemEvolution;

namespace {
        constexpr std::string_view EVOLUTION_XP_KEY = "evolutionXp";
        constexpr std::string_view EVOLUTION_BASE_ATTACK_KEY = "evolutionBaseAttack";
        constexpr std::string_view EVOLUTION_BASE_DEFENSE_KEY = "evolutionBaseDefense";
        constexpr std::string_view EVOLUTION_BASE_EXTRA_DEFENSE_KEY = "evolutionBaseExtraDefense";
        constexpr std::string_view EVOLUTION_BASE_ARMOR_KEY = "evolutionBaseArmor";
        constexpr std::string_view EVOLUTION_BASE_NAME_KEY = "evolutionBaseName";

        constexpr size_t toIndex(ConfigManager::EvolutionItemCategory category) {
                return static_cast<size_t>(category);
        }

const ConfigManager::EvolutionCategoryConfig* getCategoryConfig(ConfigManager::EvolutionItemCategory category) {
                const auto& config = ConfigManager::getWeaponEvolutionConfig();
                if (!config.enabled) {
                        return nullptr;
                }
                const auto index = toIndex(category);
                if (index >= config.categories.size()) {
                        return nullptr;
                }
                const auto& categoryConfig = config.categories[index];
                if (categoryConfig.stages.empty()) {
                        return nullptr;
                }
                return &categoryConfig;
        }

        template <typename T>
        T getStoredValue(Item* item, std::string_view key, T defaultValue) {
                if (const auto* attribute = item->getCustomAttribute(std::string(key))) {
                        auto* mutableAttr = const_cast<ItemAttributes::CustomAttribute*>(attribute);
                        if constexpr (std::is_same_v<T, int32_t>) {
                                return static_cast<int32_t>(mutableAttr->template get<int64_t>());
                        } else if constexpr (std::is_same_v<T, uint64_t>) {
                                return static_cast<uint64_t>(mutableAttr->template get<int64_t>());
                        } else if constexpr (std::is_same_v<T, std::string>) {
                                return mutableAttr->template get<std::string>();
                        }
                }
                return defaultValue;
        }

        template <typename T>
        void storeValue(Item* item, std::string_view key, T value) {
                if constexpr (std::is_same_v<T, std::string>) {
                        item->setCustomAttribute(key, value);
                } else {
                        item->setCustomAttribute(key, static_cast<int64_t>(value));
                }
        }

        ConfigManager::EvolutionItemCategory mapWeaponType(WeaponType_t type) {
                switch (type) {
                        case WEAPON_SWORD:
                        case WEAPON_CLUB:
                        case WEAPON_AXE:
                                return ConfigManager::EvolutionItemCategory::MELEE;

                        case WEAPON_DISTANCE:
                                return ConfigManager::EvolutionItemCategory::DISTANCE;

                        case WEAPON_WAND:
                                return ConfigManager::EvolutionItemCategory::WAND;

                        default:
                                break;
                }
                return ConfigManager::EvolutionItemCategory::LAST;
        }
}

void ItemEvolution::onWeaponUsed(Player* player, Item* item) const {
        if (!player || !item) {
                return;
        }

        Item* trackedItem = item;
        WeaponType_t weaponType = item->getWeaponType();
        if (weaponType == WEAPON_AMMO) {
                trackedItem = player->getWeapon(true);
                if (!trackedItem) {
                        return;
                }
                weaponType = trackedItem->getWeaponType();
        }

        const Category category = mapWeaponType(weaponType);
        if (category == Category::LAST) {
                return;
        }

        addExperience(player, trackedItem, category, 0);
}

void ItemEvolution::onShieldBlock(Player* player) const {
        if (!player) {
                return;
        }

        const Item* shield;
        const Item* weapon;
        player->getShieldAndWeapon(shield, weapon);
        if (!shield) {
                return;
        }

        addExperience(player, const_cast<Item*>(shield), Category::SHIELD, 0);
}

void ItemEvolution::onArmorHit(Player* player, int32_t damage) const {
        if (!player || damage <= 0) {
                return;
        }

        Item* armor = player->getInventoryItem(CONST_SLOT_ARMOR);
        if (!armor) {
                return;
        }

        addExperience(player, armor, Category::ARMOR, 0);
}

void ItemEvolution::modifyDamage(Player* player, Item* item, CombatDamage& damage) const {
        if (!player || !item) {
                        return;
        }

        Item* trackedItem = item;
        WeaponType_t weaponType = item->getWeaponType();
        if (weaponType == WEAPON_AMMO) {
                trackedItem = player->getWeapon(true);
                if (!trackedItem) {
                        return;
                }
                weaponType = trackedItem->getWeaponType();
        }

        if (weaponType != WEAPON_WAND) {
                return;
        }

        const auto* categoryConfig = getCategoryConfig(Category::WAND);
        if (!categoryConfig) {
                return;
        }

        const uint64_t experience = getExperience(trackedItem);
        const uint32_t stage = calculateStage(*categoryConfig, experience);
        if (stage >= categoryConfig->stages.size()) {
                return;
        }

        const auto& stageConfig = categoryConfig->stages[stage];
        int32_t minBonus = stageConfig.wandMinBonus;
        int32_t maxBonus = stageConfig.wandMaxBonus;
        if (minBonus == 0 && maxBonus == 0) {
                return;
        }

        if (maxBonus < minBonus) {
                std::swap(maxBonus, minBonus);
        }

        const int32_t bonus = uniform_random(minBonus, maxBonus);
        damage.primary.value -= std::max<int32_t>(0, bonus);
}

std::string ItemEvolution::getPlayerTitle(const Player* player) const {
        if (!player) {
                return {};
        }

        const auto& config = ConfigManager::getWeaponEvolutionConfig();
        if (!config.enabled) {
                return {};
        }

        struct Candidate {
                uint32_t stage = 0;
                uint64_t experience = 0;
                std::string name;
        } bestCandidate;

        auto considerItem = [&](Item* item, Category category) {
                if (!item) {
                        return;
                }
                const auto* categoryConfig = getCategoryConfig(category);
                if (!categoryConfig) {
                        return;
                }
                const uint64_t experience = getExperience(item);
                const uint32_t stage = calculateStage(*categoryConfig, experience);
                if (stage == 0) {
                        return;
                }
                const std::string name = buildEvolutionName(item, category, *categoryConfig, stage);
                if (name.empty()) {
                        return;
                }
                if (stage > bestCandidate.stage || (stage == bestCandidate.stage && experience > bestCandidate.experience)) {
                        bestCandidate.stage = stage;
                        bestCandidate.experience = experience;
                        bestCandidate.name = name;
                }
        };

        if (Item* leftHand = player->getInventoryItem(CONST_SLOT_LEFT)) {
                considerItem(leftHand, mapWeaponType(leftHand->getWeaponType()));
        }
        if (Item* rightHand = player->getInventoryItem(CONST_SLOT_RIGHT)) {
                considerItem(rightHand, mapWeaponType(rightHand->getWeaponType()));
        }
        if (Item* armor = player->getInventoryItem(CONST_SLOT_ARMOR)) {
                considerItem(armor, Category::ARMOR);
        }

        const Item* shield;
        const Item* weapon;
        player->getShieldAndWeapon(shield, weapon);
        if (shield) {
                considerItem(const_cast<Item*>(shield), Category::SHIELD);
        }

        if (!bestCandidate.name.empty()) {
                return bestCandidate.name;
        }
        return {};
}

void ItemEvolution::addExperience(Player* player, Item* item, Category category, uint32_t explicitGain) const {
        if (!item) {
                return;
        }

        const auto* categoryConfig = getCategoryConfig(category);
        if (!categoryConfig) {
                return;
        }

        ensureBaseData(item, category);

        uint64_t experience = getExperience(item);
        const uint32_t oldStage = calculateStage(*categoryConfig, experience);

        uint32_t gain = explicitGain;
        if (gain == 0) {
                gain = std::max<uint32_t>(1, categoryConfig->xpPerUse);
        }

        experience += gain;
        if (!categoryConfig->stages.empty()) {
                const uint64_t maxThreshold = categoryConfig->stages.back().xpRequired;
                if (experience > maxThreshold) {
                        experience = maxThreshold;
                }
        }

        setExperience(item, experience);

        const uint32_t newStage = calculateStage(*categoryConfig, experience);
        if (newStage != oldStage) {
                applyStage(player, item, category, *categoryConfig, newStage);
        }

        if (player && gain > 0) {
                sendExperienceGainMessage(player, item, *categoryConfig, experience, gain, newStage);
        }

        if (player && newStage != oldStage) {
                sendStageAdvanceMessage(player, item, category, *categoryConfig, newStage);
        }
}

uint64_t ItemEvolution::getExperience(Item* item) const {
        if (!item) {
                return 0;
        }

        if (const auto* attribute = item->getCustomAttribute(std::string(EVOLUTION_XP_KEY))) {
                auto* mutableAttr = const_cast<ItemAttributes::CustomAttribute*>(attribute);
                return std::max<int64_t>(0, mutableAttr->get<int64_t>());
        }
        return 0;
}

void ItemEvolution::setExperience(Item* item, uint64_t value) const {
        if (!item) {
                return;
        }
        item->setCustomAttribute(EVOLUTION_XP_KEY, static_cast<int64_t>(value));
}

uint32_t ItemEvolution::calculateStage(const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint64_t experience) const {
        uint32_t stage = 0;
        for (uint32_t index = 0, size = static_cast<uint32_t>(categoryConfig.stages.size()); index < size; ++index) {
                if (experience >= categoryConfig.stages[index].xpRequired) {
                        stage = index;
                } else {
                        break;
                }
        }
        return stage;
}

void ItemEvolution::ensureBaseData(Item* item, Category category) const {
        if (!item) {
                return;
        }

        const ItemType& type = Item::items[item->getID()];

        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_NAME_KEY))) {
                        std::string baseName;
                        if (item->hasAttribute(ITEM_ATTRIBUTE_NAME)) {
                                baseName = item->getStrAttr(ITEM_ATTRIBUTE_NAME);
                        } else {
                                baseName = type.name;
                        }
                        storeValue(item, EVOLUTION_BASE_NAME_KEY, baseName);
        }

        switch (category) {
                case Category::MELEE:
                case Category::DISTANCE:
                case Category::WAND: {
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_ATTACK_KEY))) {
                                storeValue(item, EVOLUTION_BASE_ATTACK_KEY, type.attack);
                        }
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_DEFENSE_KEY))) {
                                storeValue(item, EVOLUTION_BASE_DEFENSE_KEY, type.defense);
                        }
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_EXTRA_DEFENSE_KEY))) {
                                storeValue(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, type.extraDefense);
                        }
                        break;
                }

                case Category::SHIELD: {
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_DEFENSE_KEY))) {
                                storeValue(item, EVOLUTION_BASE_DEFENSE_KEY, type.defense);
                        }
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_EXTRA_DEFENSE_KEY))) {
                                storeValue(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, type.extraDefense);
                        }
                        break;
                }

                case Category::ARMOR: {
                        if (!item->getCustomAttribute(std::string(EVOLUTION_BASE_ARMOR_KEY))) {
                                storeValue(item, EVOLUTION_BASE_ARMOR_KEY, type.armor);
                        }
                        break;
                }

                case Category::LAST:
                        break;
        }
}

void ItemEvolution::applyStage(Player* player, Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const {
        if (!item) {
                return;
        }

        const auto& stageConfig = categoryConfig.stages[stage];

        switch (category) {
                case Category::MELEE:
                case Category::DISTANCE:
                case Category::WAND: {
                        const int32_t baseAttack = getStoredValue<int32_t>(item, EVOLUTION_BASE_ATTACK_KEY, Item::items[item->getID()].attack);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_ATTACK, baseAttack, stageConfig.attackBonus);

                        const int32_t baseDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_DEFENSE_KEY, Item::items[item->getID()].defense);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_DEFENSE, baseDefense, stageConfig.defenseBonus);

                        const int32_t baseExtraDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, Item::items[item->getID()].extraDefense);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_EXTRADEFENSE, baseExtraDefense, stageConfig.extraDefenseBonus);
                        break;
                }

                case Category::SHIELD: {
                        const int32_t baseDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_DEFENSE_KEY, Item::items[item->getID()].defense);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_DEFENSE, baseDefense, stageConfig.defenseBonus);

                        const int32_t baseExtraDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, Item::items[item->getID()].extraDefense);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_EXTRADEFENSE, baseExtraDefense, stageConfig.extraDefenseBonus);
                        break;
                }

                case Category::ARMOR: {
                        const int32_t baseArmor = getStoredValue<int32_t>(item, EVOLUTION_BASE_ARMOR_KEY, Item::items[item->getID()].armor);
                        applyNumericAttribute(item, ITEM_ATTRIBUTE_ARMOR, baseArmor, stageConfig.armorBonus);
                        break;
                }

                case Category::LAST:
                        break;
        }

        updateItemName(item, category, categoryConfig, stage);

        if (player) {
                slots_t slot;
                if (findEquippedSlot(player, item, slot)) {
                        player->sendInventoryItem(slot, item);
                }
        }
}

void ItemEvolution::applyNumericAttribute(Item* item, itemAttrTypes attribute, int32_t baseValue, int32_t bonusValue) const {
        if (!item) {
                return;
        }

        const int32_t finalValue = baseValue + bonusValue;
        if (finalValue == baseValue) {
                if (item->hasAttribute(attribute)) {
                        item->removeAttribute(attribute);
                }
        } else {
                item->setIntAttr(attribute, finalValue);
        }
}

void ItemEvolution::updateItemName(Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const {
        if (!item) {
                return;
        }

        if (stage == 0) {
                if (const auto* baseName = item->getCustomAttribute(std::string(EVOLUTION_BASE_NAME_KEY))) {
                        auto* mutableAttr = const_cast<ItemAttributes::CustomAttribute*>(baseName);
                        const std::string& storedName = mutableAttr->template get<std::string>();
                        if (storedName.empty()) {
                                item->removeAttribute(ITEM_ATTRIBUTE_NAME);
                        } else {
                                item->setStrAttr(ITEM_ATTRIBUTE_NAME, storedName);
                        }
                } else {
                        item->removeAttribute(ITEM_ATTRIBUTE_NAME);
                }
                return;
        }

        const std::string name = buildEvolutionName(item, category, categoryConfig, stage);
        if (!name.empty()) {
                item->setStrAttr(ITEM_ATTRIBUTE_NAME, name);
        }
}

std::string ItemEvolution::buildEvolutionName(Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const {
        const auto& globalConfig = ConfigManager::getWeaponEvolutionConfig();
        const auto& names = globalConfig.names;

        std::string baseName;
        const uint32_t stageIndex = stage > 0 ? stage - 1 : 0;

        if (!categoryConfig.baseNames.empty()) {
                baseName = categoryConfig.baseNames[stageIndex % categoryConfig.baseNames.size()];
        } else if (const auto* storedName = item->getCustomAttribute(std::string(EVOLUTION_BASE_NAME_KEY))) {
                auto* mutableAttr = const_cast<ItemAttributes::CustomAttribute*>(storedName);
                baseName = mutableAttr->template get<std::string>();
        } else {
                baseName = Item::items[item->getID()].name;
        }

        if (!names.materials.empty()) {
                const std::string& material = names.materials[stageIndex % names.materials.size()];
                if (!material.empty()) {
                        baseName = material + ' ' + baseName;
                }
        }

        const auto& corruptedNames = names.corrupted[toIndex(category)];
        if (!corruptedNames.empty() && stage + 1 == categoryConfig.stages.size()) {
                const std::string& corrupted = corruptedNames[stageIndex % corruptedNames.size()];
                if (!corrupted.empty()) {
                        baseName = corrupted;
                }
        }

        std::string prefix;
        if (!names.prefixes.empty() && stage > 0) {
                prefix = names.prefixes[stageIndex % names.prefixes.size()];
        }

        std::string tier;
        if (!names.tierNames.empty()) {
                tier = names.tierNames[std::min<size_t>(stageIndex, names.tierNames.size() - 1)];
        }

        std::string suffix;
        if (!names.suffixes.empty() && stage > 0) {
                suffix = names.suffixes[stageIndex % names.suffixes.size()];
        }

        std::string fullName;
        if (!prefix.empty()) {
                fullName += prefix + ' ';
        }
        if (!tier.empty()) {
                fullName += tier + ' ';
        }
        fullName += baseName;
        if (!suffix.empty()) {
                fullName += ' ' + suffix;
        }
        return fullName;
}

void ItemEvolution::sendExperienceGainMessage(Player* player, Item* item, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint64_t experience, uint32_t gain, uint32_t stage) const {
        if (!player || !item || gain == 0) {
                return;
        }

        std::string itemName = item->getName();
        if (itemName.empty()) {
                itemName = Item::items[item->getID()].name;
        }

        std::string progress;
        if (!categoryConfig.stages.empty()) {
                if (stage + 1 < categoryConfig.stages.size()) {
                        const uint64_t nextThreshold = categoryConfig.stages[stage + 1].xpRequired;
                        progress = fmt::format(" ({}/{})", experience, nextThreshold);
                } else {
                        progress = fmt::format(" ({}/MAX)", experience);
                }
        }

        player->sendTextMessage(MESSAGE_INFO_DESCR, fmt::format("Your {:s} gained {:d} evolution experience{:s}.", itemName, gain, progress));
}

void ItemEvolution::sendStageAdvanceMessage(Player* player, Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const {
        if (!player || !item || stage == 0 || stage >= categoryConfig.stages.size()) {
                return;
        }

        const auto& stageConfig = categoryConfig.stages[stage];
        const auto& globalConfig = ConfigManager::getWeaponEvolutionConfig();

        std::string tierName;
        if (!globalConfig.names.tierNames.empty()) {
                const size_t index = std::min<size_t>(stage - 1, globalConfig.names.tierNames.size() - 1);
                tierName = globalConfig.names.tierNames[index];
        }

        std::string rankText;
        if (!tierName.empty()) {
                rankText = fmt::format("rank {:d} ({:s})", stage, tierName);
        } else {
                rankText = fmt::format("rank {:d}", stage);
        }

        std::string baseName = getStoredValue<std::string>(item, EVOLUTION_BASE_NAME_KEY, Item::items[item->getID()].name);
        if (baseName.empty()) {
                baseName = Item::items[item->getID()].name;
        }

        std::vector<std::string> statParts;
        auto pushStat = [&statParts](std::string label, int32_t baseValue, int32_t bonusValue) {
                const int32_t finalValue = baseValue + bonusValue;
                if (finalValue != 0 || bonusValue != 0) {
                        statParts.emplace_back(fmt::format("{:s} {:d}", label, finalValue));
                }
        };

        switch (category) {
                case Category::MELEE:
                case Category::DISTANCE:
                case Category::WAND: {
                        const int32_t baseAttack = getStoredValue<int32_t>(item, EVOLUTION_BASE_ATTACK_KEY, Item::items[item->getID()].attack);
                        pushStat("Attack", baseAttack, stageConfig.attackBonus);

                        const int32_t baseDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_DEFENSE_KEY, Item::items[item->getID()].defense);
                        pushStat("Defense", baseDefense, stageConfig.defenseBonus);

                        const int32_t baseExtraDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, Item::items[item->getID()].extraDefense);
                        pushStat("Extra defense", baseExtraDefense, stageConfig.extraDefenseBonus);

                        if (category == Category::WAND) {
                                int32_t minBonus = stageConfig.wandMinBonus;
                                int32_t maxBonus = stageConfig.wandMaxBonus;
                                if (maxBonus < minBonus) {
                                        std::swap(maxBonus, minBonus);
                                }
                                if (minBonus != 0 || maxBonus != 0) {
                                        statParts.emplace_back(fmt::format("Magic damage bonus {:d} to {:d}", minBonus, maxBonus));
                                }
                        }
                        break;
                }

                case Category::SHIELD: {
                        const int32_t baseDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_DEFENSE_KEY, Item::items[item->getID()].defense);
                        pushStat("Defense", baseDefense, stageConfig.defenseBonus);

                        const int32_t baseExtraDefense = getStoredValue<int32_t>(item, EVOLUTION_BASE_EXTRA_DEFENSE_KEY, Item::items[item->getID()].extraDefense);
                        pushStat("Extra defense", baseExtraDefense, stageConfig.extraDefenseBonus);
                        break;
                }

                case Category::ARMOR: {
                        const int32_t baseArmor = getStoredValue<int32_t>(item, EVOLUTION_BASE_ARMOR_KEY, Item::items[item->getID()].armor);
                        pushStat("Armor", baseArmor, stageConfig.armorBonus);
                        break;
                }

                case Category::LAST:
                        break;
        }

        std::string statsText;
        if (!statParts.empty()) {
                        statsText = "Stats: ";
                        for (size_t index = 0; index < statParts.size(); ++index) {
                                if (index > 0) {
                                        statsText += ", ";
                                }
                                statsText += statParts[index];
                        }
        }

        std::string message = fmt::format("Your {:s} advanced to {:s}. New name: {:s}.", baseName, rankText, item->getName());
        if (!statsText.empty()) {
                message += ' ' + statsText;
        }

        player->sendTextMessage(MESSAGE_EVENT_ADVANCE, message);
}

bool ItemEvolution::findEquippedSlot(const Player* player, const Item* item, slots_t& slotOut) const {
        if (!player || !item) {
                return false;
        }

        for (int slot = CONST_SLOT_FIRST; slot <= CONST_SLOT_LAST; ++slot) {
                Item* equipped = player->getInventoryItem(static_cast<slots_t>(slot));
                if (equipped == item) {
                        slotOut = static_cast<slots_t>(slot);
                        return true;
                }
        }
        return false;
}
