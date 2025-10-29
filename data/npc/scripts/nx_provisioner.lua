local NX = (dofile_once and dofile_once('data/lib/nx_professions.lua')) or dofile('data/lib/nx_professions.lua')

local keywordHandler = KeywordHandler:new()
local npcHandler = NpcHandler:new(keywordHandler)
NpcSystem.parseParameters(npcHandler)

function onCreatureAppear(cid)              npcHandler:onCreatureAppear(cid)            end
function onCreatureDisappear(cid)           npcHandler:onCreatureDisappear(cid)         end
function onCreatureSay(cid, type, msg)      npcHandler:onCreatureSay(cid, type, msg)    end
function onThink()                          npcHandler:onThink()                        end

local shopModule = ShopModule:new()
npcHandler:addModule(shopModule)

local function addToolOffers(kind, basePrice)
        local variants = NX_TOOL_VARIANTS[kind]
        if not variants then
                return
        end
        for _, id in ipairs(variants) do
                if NX_IdExists(id) then
                        local itemType = ItemType(id)
                        if itemType then
                                local name = itemType:getName()
                                local price = basePrice
                                local lowerName = name:lower()
                                if lowerName:find("mechanical", 1, true) or lowerName:find("enhanced", 1, true) then
                                        price = basePrice + 250
                                end
                                shopModule:addBuyableItem({name}, id, price, 1, name)
                        end
                end
        end
end

addToolOffers('rod', 150)
addToolOffers('pick', 120)
addToolOffers('knife', 40)

local function addBait(name, price)
        local id = NX_ItemId(name)
        if id then
                local itemType = ItemType(id)
                local displayName = itemType and itemType:getName() or name
                shopModule:addBuyableItem({displayName}, id, price, 1, displayName)
        end
end

addBait('Worm', 5)
addBait('White Pearl', 320)
addBait('Black Pearl', 900)

keywordHandler:addKeyword({'job'}, StdModule.say, {npcHandler = npcHandler, text = 'I keep gatherers supplied with sturdy tools and bait. Ask for a {trade} to browse.'})
keywordHandler:addKeyword({'tools'}, StdModule.say, {npcHandler = npcHandler, text = 'Rods for fishing, knives for foraging and sturdy picks for mining. All crafted to last.'})
keywordHandler:addKeyword({'bait'}, StdModule.say, {npcHandler = npcHandler, text = 'Pearls tempt the rarest catches, worms keep the basics biting.'})
keywordHandler:addKeyword({'trade'}, StdModule.say, {npcHandler = npcHandler, text = 'Take your time. Everything here is balanced for field work.'})

npcHandler:setMessage(MESSAGE_GREET, 'Greetings, traveler. Provisioner Nix keeps gatherers ready. Need supplies? Say {trade}.')
npcHandler:setMessage(MESSAGE_FAREWELL, 'Stay resourceful out there.')
npcHandler:setMessage(MESSAGE_WALKAWAY, 'May your next haul be plentiful.')

npcHandler:addModule(FocusModule:new())
