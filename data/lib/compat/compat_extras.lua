-- Polyfills for environments missing these helpers
if type(table.contains) ~= 'function' then
    function table.contains(t, v)
        if t == nil then return false end
        for _, x in pairs(t) do
            if x == v then return true end
        end
        return false
    end
end

if type(string.contains) ~= 'function' then
    function string.contains(s, sub, plain)
        if s == nil or sub == nil then return false end
        return string.find(s, sub, 1, plain == true) ~= nil
    end
end
