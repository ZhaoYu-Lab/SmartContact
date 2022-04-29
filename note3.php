//投票合约
//以下的合约有一些复杂，但展示了很多Solidity的语言特性。它实现了一个投票合约。 
//当然，电子投票的主要问题是如何将投票权分配给正确的人员以及如何防止被操纵。 
//我们不会在这里解决所有的问题，但至少我们会展示如何进行委托投票，同时，计票又是 自动和完全透明的 。
//我们的想法是为每个（投票）表决创建一份合约，为每个选项提供简称。 
//然后作为合约的创造者——即主席，将给予每个独立的地址以投票权。

//地址后面的人可以选择自己投票，或者委托给他们信任的人来投票。

//在投票时间结束时，winningProposal() 将返回获得最多投票的提案。

//该智能合约实现了一个自动化且透明的投票应用。
//投票发起人可以发起投票，将投票权赋予投票人；投票人可以自己投票，或将自己的票委托给其他投票人；
//任何人都可以公开查询投票的结果。



// SPDX-License-Identifier: GPL-3.0
pragma solidity >=0.7.0 <0.9.0;   //版本指定

//Solidity中的合约（contract）类似面向对象编程语言中的类。
//每个合约可以包含状态变量、函数、事件、结构体类型和枚举类型等。
//一个合约也可以继承另一个合约。

/// @title 委托投票
contract Ballot {   //合约Ballot 类似于 类Class 
    // 这里声明了一个新的复合类型用于稍后的变量
    // 它用来表示一个选民
    struct Voter {  //定义结构体 投票人
	//其属性包括uint weight（该投票人的权重）、bool voted（是否已投票）
	//address delegate（如果该投票人将投票委托给他人，则记录受委托人的账户地址）
	//uint vote（投票做出的选择，即相应提案的索引号）
        uint weight; // 计票的权重
        bool voted;  // 若为真，代表该人已投票
        address delegate; // 被委托人
        uint vote;   // 投票提案的索引
    }

    // 提案的类型
    struct Proposal {  //定义结构体 提案，其属性包括bytes32 name（名称）和uint voteCount（获得的票数）。
        bytes32 name;   // 简称（最长32个字节）
        uint voteCount; // 得票数
    }
		
	//address类型记录了一个以太坊账户的地址。address可看作一个数值类型，但也包括一些与以太币相 关的方法
	//如查询余额<address>.balance、向该地址转账<address>.transfer（uint256 amount）等。
	
	//合约中的状态变量会长期保存在区块链中。通过调用合约中的函数，这些状态变量可以被读取和改写。
	//本例中声明了3个状态变量：chairperson、voters、proposals：
	//address public chairperson：投票发起人，类型为address；
	//mapping（address=>Voter）public voters：所有投票人，类型为address到Voter的映射；
	//Proposal[]public proposals：所有提案，类型为动态大小的Proposal数组。
	//3个状态变量都使用了public关键字，使得变量可以被外部访问（即通过消息调用）。
	//事实上，编译器会自动为public的变量创建同名的getter函数，供外部直接读取。
	//状态变量还可设置为internal或private。
	//internal的状态变量只能被该合约和继承该合约的子合约访问，private的状态变量只能被该合约访问。
	//状态变量默认为internal。
	//将上述关键状态信息设置为public能够增加投票的公平性和透明性。

    address public chairperson;

    // 这声明了一个状态变量，为每个可能的地址存储一个 `Voter`。
    mapping(address => Voter) public voters;

    // 一个 `Proposal` 结构类型的动态数组
    Proposal[] public proposals;
	
	//合约中的函数用于处理业务逻辑。函数的可见性默认为public，
	//即可以从内部或外部调用，是合约的对外接口。
	//函数可见性也可设置为external、internal和private。

	//构造函数也可以认为是函数-function Ballot() 用于初始化参数和创建投票-发起人发起投票  
	//创建一个新的投票。所有提案的名称通过参数bytes32[]proposalNames 传入
	//逐个记录到状态变量 proposals 中。
	//同时用msg.sender获取当前调用消息的发送者的地址，记录为投票发起人chairperson
	//该发起人投票权重设为1。
	
    /// 为 `proposalNames` 中的每个提案，创建一个新的（投票）表决
    constructor(bytes32[] memory proposalNames) {
        chairperson = msg.sender;  //调用该函数的人即为发起人  或是构造函数构造出来
        voters[chairperson].weight = 1;
        //对于提供的每个提案名称，
        //创建一个新的 Proposal 对象并把它添加到数组的末尾。
        for (uint i = 0; i < proposalNames.length; i++) {
            // `Proposal({...})` 创建一个临时 Proposal 对象，
            // `proposals.push(...)` 将其添加到 `proposals` 的末尾
            proposals.push(Proposal({
                name: proposalNames[i],
                voteCount: 0
            }));
        }
    }

	//给投票人赋予投票权。
	//该函数给 address voter 赋予投票权，即将 voter 的投票权重设为1，
	//存入 voters 状态变量。
	//这个函数只有投票发起人 chairperson 可以调用。
	//这里用到了 require（（msg.sender==chairperson）&&！voters[voter].voted）函数。
	//如果 require 中表达式结果为 false，这次调用会中止，
	//且回滚所有状态和以太币余额的改变到调用前。但已消耗的Gas不会返还。

    // 授权 `voter` 对这个（投票）表决进行投票
    // 只有 `chairperson` 可以调用该函数。
    function giveRightToVote(address voter) public {
        // 若 `require` 的第一个参数的计算结果为 `false`，
        // 则终止执行，撤销所有对状态和以太币余额的改动。
        // 在旧版的 EVM 中这曾经会消耗所有 gas，但现在不会了。
        // 使用 require 来检查函数是否被正确地调用，是一个好习惯。
        // 你也可以在 require 的第二个参数中提供一个对错误情况的解释。
        require(
            msg.sender == chairperson,
            "Only chairperson can give right to vote."
        );
        require(
            !voters[voter].voted,
            "The voter already voted."
        );
        require(voters[voter].weight == 0);
        voters[voter].weight = 1;
    }
	
	//把投票委托给其他投票人。
	//其中，用 voters[msg.sender] 获取委托人，即此次调用的发起人。
	//用 require 确保发起人没有投过票， 且不是委托给自己。
	//由于被委托人也可能已将投票委托出去，
	//所以接下来，用while循环查找最终的投票代表。
	//找到后，如果投票代表已投票，则将委托人的权重加到所投的提案上；
	//如果投票代表还未投票，则将委托人的权重加到代表的权重上。
	
	//该函数使用了while循环，这里合约编写者需要十分谨慎，防止调用者消耗过多Gas，
	//甚至出现死循环。
	
    /// 把你的投票委托到投票者 `to`。
    function delegate(address to) public {
        // 传引用
        Voter storage sender = voters[msg.sender];
        require(!sender.voted, "You already voted.");
        require(to != msg.sender, "Self-delegation is disallowed.");

        // 委托是可以传递的，只要被委托者 `to` 也设置了委托。
        // 一般来说，这种循环委托是危险的。因为，如果传递的链条太长，
        // 则可能需消耗的gas要多于区块中剩余的（大于区块设置的gasLimit），
        // 这种情况下，委托不会被执行。
        // 而在另一些情况下，如果形成闭环，则会让合约完全卡住。
        while (voters[to].delegate != address(0)) {
            to = voters[to].delegate;

            // 不允许闭环委托
            require(to != msg.sender, "Found loop in delegation.");
        }

        // `sender` 是一个引用, 相当于对 `voters[msg.sender].voted` 进行修改
        sender.voted = true;
        sender.delegate = to;
        Voter storage delegate_ = voters[to];
        if (delegate_.voted) {
            // 若被委托者已经投过票了，直接增加得票数
            proposals[delegate_.vote].voteCount += sender.weight;
        } else {
            // 若被委托者还没投票，增加委托者的权重
            delegate_.weight += sender.weight;
        }
    }
	
	//用函数function vote（uint proposal）实现投票过程。
	//其中，用voters[msg.sender] 获取投票人，即此次调用的发起人。
	//接下来检查是否是重复投票，如果不是，进行投票后相关状态变量的更新。

    /// 把你的票(包括委托给你的票)，
    /// 投给提案 `proposals[proposal].name`.
    function vote(uint proposal) public {
        Voter storage sender = voters[msg.sender];
        require(!sender.voted, "Already voted.");
        sender.voted = true;
        sender.vote = proposal;

        // 如果 `proposal` 超过了数组的范围，则会自动抛出异常，并恢复所有的改动
        proposals[proposal].voteCount += sender.weight;
    }
	
	//用函数function winningProposal（）constant returns（uint winningProposal）
	//这里是 view
	//将返回获胜提案的索引号。

	//这里，returns（uint winningProposal）指定了函数的返回值类型，
	///////constant 表示该函数不会改变合约状态变量的值。

	//函数通过遍历所有提案进行记票，得到获胜提案。

    /// @dev 结合之前所有的投票，计算出最终胜出的提案
    function winningProposal() public view returns (uint winningProposal_)
    {
        uint winningVoteCount = 0;
        for (uint p = 0; p < proposals.length; p++) {
            if (proposals[p].voteCount > winningVoteCount) {
                winningVoteCount = proposals[p].voteCount;
                winningProposal_ = p;
            }
        }
    }
	
	//用函数function winnerName（）constant returns（bytes32 winnerName）
	//实现返回获胜者的名称。
	//这里采用内部调用 winningProposal（）函数的方式获得获胜提案。
	//如果需要采用外部调用，则需 要写为 this.winningProposal（）。

    // 调用 winningProposal() 函数以获取提案数组中获胜者的索引，并以此返回获胜者的名称
    function winnerName() public view returns (bytes32 winnerName_)
    {
        winnerName_ = proposals[winningProposal()].name;
    }
}