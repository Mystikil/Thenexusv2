#ifndef FS_ITEMEVOLUTION_H
#define FS_ITEMEVOLUTION_H

#include <cstdint>
#include <string>

#include "configmanager.h"
#include "enums.h"

class Item;
class Player;
struct CombatDamage;
enum slots_t : uint8_t;

class ItemEvolution {
        public:
                struct EvolutionProgress {
                        ConfigManager::EvolutionItemCategory category = ConfigManager::EvolutionItemCategory::LAST;
                        std::string categoryName;
                        std::string itemName;
                        std::string rankText;
                        uint64_t experience = 0;
                        uint64_t currentThreshold = 0;
                        uint64_t nextThreshold = 0;
                        uint32_t stage = 0;
                        uint32_t stageCount = 0;
                        uint32_t maxStage = 0;
                        bool atMaxStage = false;
                };

                void onWeaponUsed(Player* player, Item* item) const;
                void onShieldBlock(Player* player) const;
                void onArmorHit(Player* player, int32_t damage) const;
                void modifyDamage(Player* player, Item* item, CombatDamage& damage) const;
                std::string getPlayerTitle(const Player* player) const;
                bool getProgressInfo(const Player* player, Item* item, slots_t slot, EvolutionProgress& out) const;

        private:
                using Category = ConfigManager::EvolutionItemCategory;

                void addExperience(Player* player, Item* item, Category category, uint32_t explicitGain = 0) const;
                uint64_t getExperience(Item* item) const;
                void setExperience(Item* item, uint64_t value) const;
                uint32_t calculateStage(const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint64_t experience) const;
                void ensureBaseData(Item* item, Category category) const;
                void applyStage(Player* player, Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const;
                void applyNumericAttribute(Item* item, itemAttrTypes attribute, int32_t baseValue, int32_t bonusValue) const;
                void updateItemName(Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const;
                std::string buildEvolutionName(Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const;
                bool findEquippedSlot(const Player* player, const Item* item, slots_t& slotOut) const;
                void sendExperienceGainMessage(Player* player, Item* item, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint64_t experience, uint32_t gain, uint32_t stage) const;
                void sendStageAdvanceMessage(Player* player, Item* item, Category category, const ConfigManager::EvolutionCategoryConfig& categoryConfig, uint32_t stage) const;
};

extern ItemEvolution g_itemEvolution;

#endif // FS_ITEMEVOLUTION_H
